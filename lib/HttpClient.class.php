<?php
/**
 * HttpClient 是一个全功能的 HTTP 客户端类 (HTTP/1.1协议)
 *
 * 功能特色：
 * 1) 纯 PHP 代码实现，无需依赖任何扩展
 * 2) 允许设置各种 HTTP 头，完整的支持 Cookie
 * 3) 支持 301/302 重定向识别，可设置最多跳转的次数
 * 4) 支持 Keep-Alive 连接，重用于同一主机下的其它请求
 * 5) 支持 https 访问（需要 PHP 开启 ssl 扩展）
 * 6) 支持 POST 表单的文件上传
 * 7) 支持同时并行处理多个请求
 *
 * @author hightman <hightman@twomice.net>
 * @link http://www.hightman.cn/
 * @copyright Copyright &copy; 2008-2013 Twomice Studio
 */
//namespace HM;

/**
 * 结果解析器接口
 */
interface HttpParser
{

	/**
	 * Parse the result
	 * @param HttpResponse $res
	 * @param HttpRequest $req
	 * @param string $key
	 */
	public function parse($res, $req, $key);
}

/**
 * 连接管理器
 */
class HttpConn
{
	const MAX_BURST = 3; // 同一主机同一端口最大并发数
	const FLAG_NEW = 0x01;
	const FLAG_NEW2 = 0x02;
	const FLAG_BUSY = 0x04;
	const FLAG_OPENED = 0x08;
	const FLAG_REUSED = 0x10;
	const FLAG_SELECT = 0x20;

	protected $outBuf, $outLen;
	protected $arg, $sock, $conn, $flag = 0;
	protected static $_objs = array();
	protected static $_refs = array();
	private static $_lastError;

	/**
	 * 建立连接，内置连接池
	 * @param string $conn 连接字符串 protocal://host:port
	 * @param mixed $arg 外部参数，可用 getExArg() 取得
	 * @return HttpConn 成功返回连接对象，失败返回 false，并发已满则返回 null
	 */
	public static function connect($conn, $arg = null)
	{
		$obj = null; /* @var $obj HttpConn */
		if (!isset(self::$_objs[$conn]))
			self::$_objs[$conn] = array();
		foreach (self::$_objs[$conn] as $tmp)
		{
			if (!($tmp->flag & self::FLAG_BUSY))
			{
				HttpClient::debug('reuse conn \'', $tmp->conn, '\': ', $tmp->sock);
				$obj = $tmp;
				break;
			}
		}
		if ($obj === null && count(self::$_objs[$conn]) < self::MAX_BURST)
		{
			$obj = new self($conn);
			self::$_objs[$conn][] = $obj;
			HttpClient::debug('create conn \'', $conn, '\'');
		}
		if ($obj !== null)
		{
			if ($obj->flag & self::FLAG_OPENED)
				$obj->flag |= self::FLAG_REUSED;
			else if (!$obj->openSock()) // try to open socket
				return false;
			$obj->flag |= self::FLAG_BUSY;
			$obj->outBuf = null;
			$obj->outLen = 0;
			$obj->arg = $arg;
		}
		return $obj;
	}

	/**
	 * 按套接字反查连接对象
	 * @param resource $sock
	 * @return HttpConn 若找不到则返回 null
	 */
	public static function findBySock($sock)
	{
		$sock = strval($sock);
		return isset(self::$_refs[$sock]) ? self::$_refs[$sock] : null;
	}

	/**
	 * @return string 返回最近一次出错原因
	 */
	public static function getLastError()
	{
		return self::$_lastError;
	}

	/** 	 
	 * @param bool $realClose 是否真实关闭，默认则放入连接池备用
	 */
	public function close($realClose = false)
	{
		$this->arg = null;
		$this->flag &= ~self::FLAG_BUSY;
		if ($realClose === true)
		{
			HttpClient::debug('close conn \'', $this->conn, '\': ', $this->sock);
			$this->flag &= ~self::FLAG_OPENED;
			@fclose($this->sock);
			$this->delSockRef();
			$this->sock = false;
		}
		else
		{
			HttpClient::debug('free conn \'', $this->conn, '\': ', $this->sock);
		}
	}

	public function addWriteData($buf)
	{
		if ($this->outBuf === null)
			$this->outBuf = $buf;
		else
			$this->outBuf .= $buf;
	}

	public function hasDataToWrite()
	{
		return ($this->outBuf !== null && strlen($this->outBuf) > $this->outLen);
	}

	/**
	 * @param string $buf 不传入则从缓冲区读取未写入的数据
	 * @return mixed 返回成功写入的字节数，出错返回 false，写满时则返回 0
	 */
	public function write($buf = null)
	{
		if ($buf === null)
		{
			$len = 0;
			if ($this->hasDataToWrite())
			{
				$buf = $this->outLen > 0 ? substr($this->outBuf, $this->outLen) : $this->outBuf;
				$len = $this->write($buf);
				if ($len !== false)
					$this->outLen += $len;
			}
			return $len;
		}
		$n = fwrite($this->sock, $buf);
		if ($n === 0 && $this->ioEmptyError())
			$n = false;
		return $n;
	}

	/**
	 * 读取一行数据并返回(不包含行末的\r\n)
	 * @return mixed 出错返回 false，无数据返回 null
	 */
	public function getLine()
	{
		$line = stream_get_line($this->sock, 2048, "\n");
		if ($line === '' || $line === false)
			$line = $this->ioEmptyError() ? false : null;
		else
			$line = rtrim($line, "\r");
		$this->ioFlagReset();
		return $line;
	}

	/**
	 * @param int $size 要读取的最大字节数
	 * @return mixed 返回读到的数据，出错时返回 false，无数据返回 null
	 */
	public function read($size = 8192)
	{
		$buf = fread($this->sock, $size);
		if ($buf === '' || $buf === false)
			$buf = $this->ioEmptyError() ? false : null;
		$this->ioFlagReset();
		return $buf;
	}

	/**
	 *
	 * @return resource 返回连接套接字用于 select()
	 */
	public function getSock()
	{
		$this->flag |= self::FLAG_SELECT;
		return $this->sock;
	}

	public function getExArg()
	{
		return $this->arg;
	}

	public function __destruct()
	{
		$this->close(true);
	}

	/** 	 
	 * @param boolean $repeat 是否为重连
	 */
	protected function openSock($repeat = false)
	{
		$this->delSockRef();
		$this->flag |= self::FLAG_NEW;
		if ($repeat === true)
			$this->flag |= self::FLAG_NEW2;
		$this->sock = stream_socket_client($this->conn, $errno, $error, 1, STREAM_CLIENT_ASYNC_CONNECT);
		if ($this->sock === false)
		{
			HttpClient::debug($repeat ? 're' : '', 'open \'', $this->conn, '\' failed: ', $error);
			self::$_connError = $error;
		}
		else
		{
			HttpClient::debug($repeat ? 're' : '', 'open \'', $this->conn, '\' success: ', $this->sock);
			stream_set_blocking($this->sock, false);
			$this->flag |= self::FLAG_OPENED;
			$this->addSockRef();
		}
		$this->outLen = 0; // reset out length
		return $this->sock;
	}

	protected function ioEmptyError()
	{
		if ($this->flag & self::FLAG_SELECT)
		{
			if (!($this->flag & self::FLAG_REUSED) || !$this->openSock(true))
			{
				self::$_lastError = ($this->flag & self::FLAG_NEW) ? 'Fail to connect' : 'Reset by peer';
				return true;
			}
		}
		return false;
	}

	protected function ioFlagReset()
	{
		$this->flag &= ~(self::FLAG_NEW | self::FLAG_REUSED | self::FLAG_SELECT);
		if ($this->flag & self::FLAG_NEW2)
		{
			$this->flag |= self::FLAG_NEW;
			$this->flag ^= self::FLAG_NEW2;
		}
	}

	protected function addSockRef()
	{
		if ($this->sock !== false)
		{
			$sock = strval($this->sock);
			self::$_refs[$sock] = $this;
		}
	}

	protected function delSockRef()
	{
		if ($this->sock !== false)
		{
			$sock = strval($this->sock);
			unset(self::$_refs[$sock]);
		}
	}

	protected function __construct($conn)
	{
		$this->conn = $conn;
		$this->sock = false;
	}
}

/**
 * Http processer
 */
class HttpProcesser
{
	/** @var string */
	public $key;

	/** @var HttpClient */
	public $cli;

	/** @var HttpRequest */
	public $req;

	/** @var HttpResponse */
	public $res;

	/** @var HttpConn */
	public $conn = null;

	/** @var boolean */
	public $finished;
	private $headerOK;
	private $timeBegin, $chunkLeft;

	/** 	 
	 * @param HttpClient $cli
	 * @param HttpRequest $req 
	 */
	public function __construct($cli, $req, $key = null)
	{
		$this->cli = $cli;
		$this->req = $req;
		$this->key = $key;
		$this->res = new HttpResponse($req->getRawUrl());
		$this->finished = $this->headerOK = false;
		$this->timeBegin = microtime(true);
	}

	/**
	 * @return HttpConn 获取连接，需要排队等待或连不上则返回 null
	 */
	public function getConn()
	{
		if ($this->conn === null)
		{
			$this->conn = HttpConn::connect($this->req->getUrlParam('conn'), $this);
			if ($this->conn === false)
			{
				$this->res->error = HttpConn::getLastError();
				$this->finish();
			}
			else if ($this->conn !== null)
				$this->conn->addWriteData($this->getRequestBuf());
		}
		return $this->conn;
	}

	public function send()
	{
		if ($this->conn->write() === false)
			$this->finish('BROKEN');
	}

	public function recv()
	{
		return $this->headerOK ? $this->readBody() : $this->readHeader();
	}

	/** 	
	 * @param string $type NORMAL, BROKEN, TIMEOUT
	 */
	public function finish($type = 'NORMAL')
	{
		$this->finished = true;
		if ($type === 'BROKEN')
			$this->res->error = HttpConn::getLastError();
		else if ($type !== 'NORMAL')
			$this->res->error = ucfirst(strtolower($type));
		// gzip decode
		$encoding = $this->res->getHeader('content-encoding');
		if ($encoding !== null && strstr($encoding, 'gzip'))
			$this->res->body = HttpClient::gzdecode($this->res->body);
		// parser
		$this->res->timeCost = microtime(true) - $this->timeBegin;
		$this->cli->runParser($this->res, $this->req, $this->key);
		// conn
		if ($this->conn)
		{
			// close conn
			$close = $this->res->getHeader('connection');
			$this->conn->close($type !== 'NORMAL' || !strcasecmp($close, 'close'));
			$this->conn = null;
			// redirect
			if (($this->res->status === 301 || $this->res->status === 302)
				&& $this->res->numRedirected < $this->req->getMaxRedirect()
				&& ($location = $this->res->getHeader('location')) !== null)
			{
				HttpClient::debug('redirect to \'', $location, '\'');
				$req = $this->req;
				if (!preg_match('/^https?:\/\//i', $location))
				{
					$pa = $req->getUrlParams();
					$url = $pa['scheme'] . '://' . $pa['host'];
					if (isset($pa['port']))
						$url .= ':' . $pa['port'];
					if (substr($location, 0, 1) == '/')
						$url .= $location;
					else
						$url .= substr($pa['path'], 0, strrpos($pa['path'], '/') + 1) . $location;
					$location = $url; /// FIXME: strip relative '../../'
				}
				// change new url
				$prevUrl = $req->getUrl();
				$req->setUrl($location);
				if (!$req->getHeader('referer'))
					$req->setHeader('referer', $prevUrl);
				if ($req->getMethod() !== 'HEAD')
					$req->setMethod('GET');
				$req->clearCookie();
				$req->setHeader('host', null);
				$req->setHeader('x-server-ip', null);
				// reset response
				$this->res->numRedirected++;
				$this->finished = $this->headerOK = false;
				return $this->res->reset();
			}
		}
		HttpClient::debug('finished', $this->res->hasError() ? ' (' . $this->res->error . ')' : '');
		$this->req = $this->cli = null;
	}

	public function __destruct()
	{
		if ($this->conn)
			$this->conn->close();
		$this->req = $this->cli = $this->res = $this->conn = null;
	}

	private function readHeader()
	{
		// read header					
		while (($line = $this->conn->getLine()) !== null)
		{
			if ($line === false)
				return $this->finish('BROKEN');
			if ($line === '')
			{
				$this->headerOK = true;
				$this->chunkLeft = 0;
				return $this->readBody();
			}
			HttpClient::debug('read header line: ', $line);
			if (!strncmp('HTTP/', $line, 5))
			{
				$line = trim(substr($line, strpos($line, ' ')));
				list($this->res->status, $this->res->statusText) = explode(' ', $line, 2);
				$this->res->status = intval($this->res->status);
			}
			else if (!strncasecmp('Set-Cookie: ', $line, 12))
			{
				$cookie = $this->parseCookieLine($line);
				if ($cookie !== false)
				{
					$this->res->setRawCookie($cookie['name'], $cookie['value']);
					$this->cli->setRawCookie($cookie['name'], $cookie['value'], $cookie['expires'], $cookie['domain'], $cookie['path']);
				}
			}
			else
			{
				list($k, $v) = explode(':', $line, 2);
				$this->res->addHeader($k, trim($v));
			}
		}
	}

	private function readBody()
	{
		// head only
		if ($this->req->getMethod() === 'HEAD')
			return $this->finish();
		// chunked
		$res = $this->res;
		$conn = $this->conn;
		$length = $res->getHeader('content-length');
		$encoding = $res->getHeader('transfer-encoding');
		if ($encoding !== null && !strcasecmp($encoding, 'chunked'))
		{
			// unfinished chunk
			if ($this->chunkLeft > 0)
			{
				$buf = $conn->read($this->chunkLeft);
				if ($buf === false)
					return $this->finish('BROKEN');
				if (is_string($buf))
				{
					HttpClient::debug('read chunkLeft(', $this->chunkLeft, ')=', strlen($buf));
					$res->body .= $buf;
					$this->chunkLeft -= strlen($buf);
					if ($this->chunkLeft === 0) // strip CRLF
						$res->body = substr($res->body, 0, -2);
				}
				if ($this->chunkLeft > 0)
					return;
			}
			// next chunk
			while (($line = $conn->getLine()) !== null)
			{
				if ($line === false)
					return $this->finish('BROKEN');
				HttpClient::debug('read chunk line: ', $line);
				if (($pos = strpos($line, ';')) !== false)
					$line = substr($line, 0, $pos);
				$size = intval(hexdec(trim($line)));
				if ($size <= 0)
				{
					while ($line = $conn->getLine()) // tail header
					{
						if ($line === '')
							break;
						HttpClient::debug('read tailer line: ', $line);
						if (($pos = strpos($line, ':')) !== false)
							$res->addHeader(substr($line, 0, $pos), trim(substr($line, $pos + 1)));
					}
					return $this->finish();
				}
				// add CRLF, save to chunkLeft for next loop
				$this->chunkLeft = $size + 2; // add CRLF
				return;
			}
		}
		else if ($length !== null)
		{
			$size = intval($length) - strlen($res->body);
			if ($size > 0)
			{
				$buf = $conn->read($size);
				if ($buf === false)
					return $this->finish('BROKEN');
				if (is_string($buf))
				{
					HttpClient::debug('read fixedBody(', $size, ')=', strlen($buf));
					$res->body .= $buf;
					$size -= strlen($buf);
				}
			}
			if ($size === 0)
				return $this->finish();
		}
		else
		{
			if ($res->body === '')
				$res->setHeader('connection', 'close');
			if (($buf = $conn->read()) === false)
				return $this->finish();
			if (is_string($buf))
			{
				HttpClient::debug('read streamBody()=', strlen($buf));
				$res->body .= $buf;
			}
		}
	}

	private function parseCookieLine($line)
	{
		$now = time();
		$cookie = array('name' => '', 'value' => '', 'expires' => null, 'path' => '/');
		$cookie['domain'] = $this->req->getHeader('host');
		$tmpa = explode(';', substr($line, 12));
		foreach ($tmpa as $tmp)
		{
			if (($pos = strpos($tmp, '=')) === false)
				continue;
			$k = trim(substr($tmp, 0, $pos));
			$v = trim(substr($tmp, $pos + 1));
			if ($cookie['name'] === '')
			{
				$cookie['name'] = $k;
				$cookie['value'] = $v;
			}
			else
			{
				$k = strtolower($k);
				if ($k === 'expires')
				{
					$cookie[$k] = strtotime($v);
					if ($cookie[$k] < $now)
						$cookie['value'] = '';
				}
				else if ($k === 'domain')
				{
					$pos = strpos($cookie['domain'], $v);
					if ($pos === 0
						|| substr($cookie['domain'], $pos, 1) === '.'
						|| substr($cookie['domain'], $pos + 1, 1) === '.')
					{
						$cookie[$k] = $v;
					}
				}
				else if (isset($cookie[$k]))
				{
					$cookie[$k] = $v;
				}
			}
		}
		if ($cookie['name'] !== '')
			return $cookie;
		return false;
	}

	private function getRequestBuf()
	{
		// request line
		$cli = $this->cli;
		$req = $this->req;
		$pa = $req->getUrlParams();
		$header = $req->getMethod() . ' ' . $pa['path'];
		if (isset($pa['query']))
			$header .= '?' . $pa['query'];
		$header .= ' HTTP/1.1' . HttpClient::CRLF;
		// body (must call prior than headers)
		$body = $req->getBody();
		HttpClient::debug('request body(', strlen($body) . ')');
		// header
		$cli->applyCookie($req);
		foreach (array_merge($cli->getHeader(null), $req->getHeader(null)) as $key => $value)
		{
			$header .= $this->formatHeaderLine($key, $value);
		}
		HttpClient::debug('request header: ', HttpClient::CRLF, $header);
		return $header . HttpClient::CRLF . $body;
	}

	private function formatHeaderLine($key, $value)
	{
		if (is_array($value))
		{
			$line = '';
			foreach ($value as $val)
			{
				$line .= $this->formatHeaderLine($key, $val);
			}
			return $line;
		}
		if (strpos($key, '-') === false)
			$line = ucfirst($key);
		else
		{
			$parts = explode('-', $key);
			$line = ucfirst($parts[0]);
			for ($i = 1; $i < count($parts); $i++)
				$line .= '-' . ucfirst($parts[$i]);
		}
		$line .= ': ' . $value . HttpClient::CRLF;
		return $line;
	}
}

/**
 * Base Http Request (header + cookie)
 */
class HttpBase
{
	protected $_headers = array();
	protected $_cookies = array();

	public function setHeader($key, $value = null)
	{
		if (is_array($key))
		{
			foreach ($key as $k => $v)
			{
				$this->setHeader($k, $v);
			}
		}
		else
		{
			$key = strtolower($key);
			if ($value === null)
				unset($this->_headers[$key]);
			else
				$this->_headers[$key] = $value;
		}
	}

	public function addHeader($key, $value = null)
	{
		if (is_array($key))
		{
			foreach ($key as $k => $v)
			{
				$this->addHeader($k, $v);
			}
		}
		else if ($value !== null)
		{
			$key = strtolower($key);
			if (!isset($this->_headers[$key]))
				$this->_headers[$key] = $value;
			else if (is_array($this->_headers[$key]))
				$this->_headers[$key][] = $value;
			else
				$this->_headers[$key] = array($this->_headers[$key], $value);
		}
	}

	public function clearHeader()
	{
		$this->_headers = array();
	}

	public function getHeader($key = null)
	{
		if ($key === null)
			return $this->_headers;
		$key = strtolower($key);
		return isset($this->_headers[$key]) ? $this->_headers[$key] : null;
	}

	public function hasHeader($key)
	{
		return isset($this->_headers[strtolower($key)]);
	}

	public function setRawCookie($key, $value, $expires = null, $domain = '-', $path = '/')
	{
		$domain = strtolower($domain);
		if (substr($domain, 0, 1) === '.')
			$domain = substr($domain, 1);
		if (!isset($this->_cookies[$domain]))
			$this->_cookies[$domain] = array();
		if (!isset($this->_cookies[$domain][$path]))
			$this->_cookies[$domain][$path] = array();
		$list = &$this->_cookies[$domain][$path];
		if ($value === null || $value === '' || ($expires !== null && $expires < time()))
			unset($list[$key]);
		else
			$list[$key] = array('value' => $value, 'expires' => $expires);
	}

	public function setCookie($key, $value)
	{
		$this->setRawCookie($key, rawurlencode($value));
	}

	public function clearCookie($domain = '-', $path = null)
	{
		if ($domain === null)
			$this->_cookies = array();
		else
		{
			$domain = strtolower($domain);
			if ($path === null)
				unset($this->_cookies[$domain]);
			else if (isset($this->_cookies[$domain]))
				unset($this->_cookies[$domain][$path]);
		}
	}

	public function getCookie($key, $domain = '-')
	{
		$domain = strtolower($domain);
		if ($key === null)
			$cookies = array();
		while (true)
		{
			if (isset($this->_cookies[$domain]))
			{
				foreach ($this->_cookies[$domain] as $path => $list)
				{
					if ($key === null)
						$cookies = array_merge($list, $cookies);
					else if (isset($list[$key])) // single value
						return rawurldecode($list[$key]['value']);
				}
			}
			if (($pos = strpos($domain, '.', 1)) === false)
				break;
			$domain = substr($domain, $pos);
		}
		return $key === null ? $cookies : null;
	}

	/** 	 
	 * @param HttpRequest $req
	 */
	public function applyCookie($req)
	{
		// fetch cookies
		$host = $req->getHeader('host');
		$path = $req->getUrlParam('path');
		$cookies = $this->fetchCookieToSend($host, $path);
		if ($this !== $req)
			$cookies = array_merge($cookies, $req->fetchCookieToSend($host, $path));

		// add to header
		$req->setHeader('cookie', null);
		foreach (array_chunk(array_values($cookies), 3) as $chunk)
		{
			$req->addHeader('cookie', implode('; ', $chunk));
		}
	}

	public function fetchCookieToSend($host, $path)
	{
		$now = time();
		$host = strtolower($host);
		$cookies = array();
		$domains = array('-', $host);
		while (strlen($host) > 1 && ($pos = strpos($host, '.', 1)) !== false)
		{
			$host = substr($host, $pos + 1);
			$domains[] = $host;
		}
		foreach ($domains as $domain)
		{
			if (!isset($this->_cookies[$domain]))
				continue;
			foreach ($this->_cookies[$domain] as $_path => $list)
			{
				if (!strncmp($_path, $path, strlen($_path))
					&& (substr($_path, -1, 1) === '/' || substr($path, strlen($_path), 1) === '/'))
				{
					foreach ($list as $k => $v)
					{
						if (!isset($cookies[$k]) && ($v['expires'] === null || $v['expires'] > $now))
							$cookies[$k] = $k . '=' . $v['value'];
					}
				}
			}
		}
		return $cookies;
	}

	protected function fetchCookieToSave()
	{
		$now = time();
		$cookies = array();
		foreach ($this->_cookies as $domain => $_list1)
		{
			$list1 = array();
			foreach ($_list1 as $path => $_list2)
			{
				$list2 = array();
				foreach ($_list2 as $k => $v)
				{
					if ($v['expires'] === null || $v['expires'] < $now)
						continue;
					$list2[$k] = $v;
				}
				if (count($list2) > 0)
					$list1[$path] = $list2;
			}
			if (count($list1) > 0)
				$cookies[$domain] = $list1;
		}
		return $cookies;
	}

	protected function loadCookie($fpath)
	{
		if (file_exists($fpath))
			$this->_cookies = unserialize(file_get_contents($fpath));
	}

	protected function saveCookie($fpath)
	{
		file_put_contents($fpath, serialize($this->fetchCookieToSave()));
	}
}

/**
 * Response Class
 */
class HttpResponse extends HttpBase
{
	public $status, $statusText, $error, $body, $timeCost, $url;
	public $numRedirected = 0;

	public function __construct($url)
	{
		$this->reset();
		$this->url = $url;
	}

	public function __toString()
	{
		return $this->body;
	}

	public function hasError()
	{
		return $this->error !== null;
	}

	public function reset()
	{
		$this->status = 400;
		$this->statusText = 'Bad Request';
		$this->body = '';
		$this->error = null;
		$this->timeCost = 0;
		$this->clearHeader();
		$this->clearCookie();
	}

	/**
	 * 在 HttpParser 回调方法或函数中使用，可在处理完后继续强制处理这个新的 url
	 * @param string $url
	 */
	public function redirect($url)
	{
		// 检查是否已经需要重定向
		if (($this->status === 301 || $this->status === 302) && ($this->hasHeader('location')))
			return;
		// FIXME: 临时解决方案 ^-^
		$this->numRedirected--;
		$this->status = 302;
		$this->setHeader('location', $url);
	}
}

/**
 * Request Class
 */
class HttpRequest extends HttpBase
{
	protected $_url, $_urlParams, $_rawUrl;
	protected $_method = 'GET';
	protected $_postFields = array();
	protected $_postFiles = array();
	protected $_maxRedirect = 5;
	protected static $_dns = array();
	protected static $_mimes = array(
		'gif' => 'image/gif', 'png' => 'image/png', 'bmp' => 'image/bmp',
		'jpeg' => 'image/jpeg', 'pjpg' => 'image/pjpg', 'jpg' => 'image/jpeg',
		'tif' => 'image/tiff', 'htm' => 'text/html', 'css' => 'text/css',
		'html' => 'text/html', 'txt' => 'text/plain', 'gz' => 'application/x-gzip',
		'tgz' => 'application/x-gzip', 'tar' => 'application/x-tar',
		'zip' => 'application/zip', 'hqx' => 'application/mac-binhex40',
		'doc' => 'application/msword', 'pdf' => 'application/pdf',
		'ps' => 'application/postcript', 'rtf' => 'application/rtf',
		'dvi' => 'application/x-dvi', 'latex' => 'application/x-latex',
		'swf' => 'application/x-shockwave-flash', 'tex' => 'application/x-tex',
		'mid' => 'audio/midi', 'au' => 'audio/basic', 'mp3' => 'audio/mpeg',
		'ram' => 'audio/x-pn-realaudio', 'ra' => 'audio/x-realaudio',
		'rm' => 'audio/x-pn-realaudio', 'wav' => 'audio/x-wav', 'wma' => 'audio/x-ms-media',
		'wmv' => 'video/x-ms-media', 'mpg' => 'video/mpeg', 'mpga' => 'video/mpeg',
		'wrl' => 'model/vrml', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo'
	);

	protected static function getIpAddr($host)
	{
		if (!isset(self::$_dns[$host]))
			self::$_dns[$host] = gethostbyname($host);
		return self::$_dns[$host];
	}

	public function __construct($url = null)
	{
		if ($url !== null)
			$this->setUrl($url);
	}

	public function setMaxRedirect($num)
	{
		$this->_maxRedirect = intval($num);
	}

	public function getMaxRedirect()
	{
		return $this->_maxRedirect;
	}

	public function setUrl($url)
	{
		$this->_rawUrl = $url;
		if (strncasecmp($url, 'http://', 7) && strncasecmp($url, 'https://', 8) && isset($_SERVER['HTTP_HOST']))
		{
			if (substr($url, 0, 1) != '/')
				$url = substr($_SERVER['SCRIPT_NAME'], 0, strrpos($_SERVER['SCRIPT_NAME'], '/') + 1) . $url;
			$url = 'http://' . $_SERVER['HTTP_HOST'] . $url;
		}
		$this->_url = str_replace('&amp;', '&', $url);
		$this->_urlParams = null;
	}

	public function getRawUrl()
	{
		return $this->_rawUrl;
	}

	public function getUrl()
	{
		return $this->_url;
	}

	public function getUrlParams()
	{
		if ($this->_urlParams === null)
		{
			$pa = @parse_url($this->getUrl());
			$pa['scheme'] = isset($pa['scheme']) ? strtolower($pa['scheme']) : 'http';
			if ($pa['scheme'] !== 'http' && $pa['scheme'] !== 'https')
			{
				trigger_error("Invalid url scheme `{$pa['scheme']}`", E_USER_WARNING);
				return false;
			}
			if (!isset($pa['host']))
			{
				trigger_error("Invalid request url, host required", E_USER_WARNING);
				return false;
			}
			if (!isset($pa['path']))
				$pa['path'] = '/';
			// basic auth
			if (isset($pa['user']) && isset($pa['pass']))
				$this->applyBasicAuth($pa['user'], $pa['pass']);
			// convert host to IP address
			$port = isset($pa['port']) ? intval($pa['port']) : ($pa['scheme'] === 'https' ? 443 : 80);
			$pa['ip'] = $this->hasHeader('x-server-ip') ?
				$this->getHeader('x-server-ip') : self::getIpAddr($pa['host']);
			$pa['conn'] = ($pa['scheme'] === 'https' ? 'ssl' : 'tcp') . '://' . $pa['ip'] . ':' . $port;
			// host header
			if (!$this->hasHeader('host'))
				$this->setHeader('host', strtolower($pa['host']));
			else
				$pa['host'] = $this->getHeader('host');
			$this->_urlParams = $pa;
		}
		return $this->_urlParams;
	}

	public function getUrlParam($key)
	{
		$pa = $this->getUrlParams();
		return isset($pa[$key]) ? $pa[$key] : null;
	}

	public function setMethod($method)
	{
		$this->_method = strtoupper($method);
	}

	public function getMethod()
	{
		return $this->_method;
	}

	public function getBody()
	{
		$data = '';
		if ($this->_method !== 'POST')
			return $data;
		if (count($this->_postFiles) > 0)
		{
			$boundary = md5($this->_rawUrl . microtime());
			foreach ($this->_postFields as $k => $v)
			{
				$data .= '--' . $boundary . HttpClient::CRLF . 'Content-Disposition: form-data; name="' . $k . '"'
					. HttpClient::CRLF . HttpClient::CRLF . $v . HttpClient::CRLF;
			}
			foreach ($this->_postFiles as $k => $v)
			{
				$ext = strtolower(substr($v[0], strrpos($v[0], '.') + 1));
				$type = isset(self::$_mimes[$ext]) ? self::$_mimes[$ext] : 'application/octet-stream';
				$data .= '--' . $boundary . HttpClient::CRLF . 'Content-Disposition: form-data; name="' . $k . '"; filename="' . $v[0] . '"'
					. HttpClient::CRLF . 'Content-Type: ' . $type . HttpClient::CRLF . 'Content-Transfer-Encoding: binary'
					. HttpClient::CRLF . HttpClient::CRLF . $v[1] . HttpClient::CRLF;
			}
			$data .= '--' . $boundary . '--' . HttpClient::CRLF;
			$this->setHeader('content-type', 'multipart/form-data; boundary=' . $boundary);
		}
		else if (count($this->_postFields) > 0)
		{
			foreach ($this->_postFields as $k => $v)
			{
				$data .= '&' . rawurlencode($k) . '=' . rawurlencode($v);
			}
			$data = substr($data, 1);
			$this->setHeader('content-type', 'application/x-www-form-urlencoded');
		}
		$this->setHeader('content-length', strlen($data));
		return $data . HttpClient::CRLF;
	}

	public function addPostField($key, $value)
	{
		$this->setMethod('POST');
		if (!is_array($value))
			$this->_postFields[$key] = strval($value);
		else
		{
			$value = $this->formatArrayField($value);
			foreach ($value as $k => $v)
			{
				$k = $key . '[' . $k . ']';
				$this->_postFields[$k] = $v;
			}
		}
	}

	public function addPostFile($key, $fname, $content = null)
	{
		$this->setMethod('POST');
		if ($content === null && is_file($fname))
			$content = @file_get_contents($fname);
		$this->_postFiles[$key] = array(basename($fname), $content);
	}

	public function __toString()
	{
		return $this->getUrl();
	}

	// format array field (convert N-DIM(n>=2) array => 2-DIM array)
	private function formatArrayField($arr, $pk = null)
	{
		$ret = array();
		foreach ($arr as $k => $v)
		{
			if ($pk !== null)
				$k = $pk . $k;
			if (is_array($v))
				$ret = array_merge($ret, $this->formatArrayField($v, $k . ']['));
			else
				$ret[$k] = $v;
		}
		return $ret;
	}

	// apply basic auth
	private function applyBasicAuth($user, $pass)
	{
		$this->setHeader('authorization', 'Basic ' . base64_encode($user . ':' . $pass));
	}
}

/**
 * Client Class
 */
class HttpClient extends HttpBase
{
	const PACKAGE = 'HttpClient';
	const VERSION = '3.0-beta';
	const CRLF = "\r\n";
	protected $_cookiePath, $_parser, $_timeout;
	private static $_debugOpen = false;
	private static $_processKey;

	public static function debug($msg)
	{
		if ($msg === 'open')
		{
			self::$_debugOpen = true;
			return;
		}
		if ($msg === 'close')
		{
			self::$_debugOpen = false;
			return;
		}
		if (self::$_debugOpen === true)
		{
			$key = self::$_processKey === null ? '' : '[' . self::$_processKey . '] ';
			echo '[DEBUG] ' . date('H:i:s') . ' ' . $key . implode('', func_get_args()) . self::CRLF;
		}
	}

	public static function gzdecode($data)
	{
		return gzinflate(substr($data, 10, -8));
	}

	public function __construct($p = null)
	{
		$this->applyDefaultHeader();
		$this->setParser($p);
	}

	/**
	 * 设置最大的网络读取最大等待时间
	 * @param int $sec 秒数，支持小数点
	 */
	public function setTimeout($sec)
	{
		$this->_timeout = floatval($sec);
	}

	/**
	 * 设置 Cookie 数据的存取路径
	 * @param string $fpath
	 */
	public function setCookiePath($fpath)
	{
		$this->_cookiePath = $fpath;
		$this->loadCookie($fpath);
	}

	public function setParser($p)
	{
		if ($p === null || $p instanceof HttpParser || is_callable($p))
			$this->_parser = $p;
	}

	/** 	 
	 * @param HttpResponse $res
	 * @param HttpRequest $req 
	 * @param mixed $key (key of multi request)
	 */
	public function runParser($res, $req, $key = null)
	{
		if ($this->_parser !== null)
		{
			self::debug('run parser: ', $req->getRawUrl());
			if ($this->_parser instanceof HttpParser)
				$this->_parser->parse($res, $req, $key);
			else
				call_user_func($this->_parser, $res, $req, $key);
		}
	}

	public function clearHeader()
	{
		parent::clearHeader();
		$this->applyDefaultHeader();
	}

	public function get($url)
	{
		return $this->process($url);
	}

	public function head($url)
	{
		if (!is_array($url))
		{
			$req = new HttpRequest($url);
			$req->setMethod('HEAD');
			return $this->process($req);
		}
		$reqs = array();
		foreach ($url as $key => $_url)
		{
			$req = new HttpRequest($_url);
			$req->setMethod('HEAD');
			$reqs[$key] = $req;
		}
		return $this->process($reqs);
	}

	public function process($url)
	{
		// build recs
		$recs = array();
		$reqs = is_array($url) ? $url : array($url);
		foreach ($reqs as $key => $req)
		{
			if (!$req instanceof HttpRequest)
				$req = new HttpRequest($req);
			$recs[$key] = new HttpProcesser($this, $req, $key);
		}

		// loop to process
		while (true)
		{
			// build select fds
			$rfds = $wfds = $xrec = array();
			$xfds = null;
			foreach ($recs as $rec) /* @var $rec HttpProcesser */
			{
				self::$_processKey = $rec->key;
				if ($rec->finished || !($conn = $rec->getConn($this)))
					continue;
				if ($this->_timeout !== null)
					$xrec[] = $rec;
				$rfds[] = $conn->getSock();
				if ($conn->hasDataToWrite())
					$wfds[] = $conn->getSock();
			}
			self::$_processKey = null;
			if (count($rfds) === 0 && count($wfds) === 0) // all tasks finished
				break;

			// select sockets
			self::debug('stream_select(rfds[', count($rfds), '], wfds[', count($wfds), ']) ...');
			if ($this->_timeout === null)
				$num = stream_select($rfds, $wfds, $xfds, null);
			else
			{
				$sec = intval($this->_timeout);
				$usec = intval(($this->_timeout - $sec) * 1000000);
				$num = stream_select($rfds, $wfds, $xfds, $sec, $usec);
			}
			self::debug('select result: ', $num === false ? 'false' : $num);
			if ($num === false)
			{
				trigger_error('stream_select() error', E_USER_WARNING);
				break;
			}
			else if ($num > 0)
			{
				// wfds
				foreach ($wfds as $sock)
				{
					if (!($conn = HttpConn::findBySock($sock)))
						continue;
					$rec = $conn->getExArg(); /* @var $rec HttpProccessRec */
					self::$_processKey = $rec->key;
					$rec->send();
				}
				// rfds
				foreach ($rfds as $sock)
				{
					if (!($conn = HttpConn::findBySock($sock)))
						continue;
					$rec = $conn->getExArg(); /* @var $rec HttpProccessRec */
					self::$_processKey = $rec->key;
					$rec->recv();
				}
			}
			else
			{
				// force to close request
				foreach ($xrec as $rec)
				{
					self::$_processKey = $rec->key;
					$rec->finish('TIMEOUT');
				}
			}
		}

		// return value
		if (!is_array($url))
			return $recs[0]->res;
		$ret = array();
		foreach ($recs as $key => $rec)
		{
			$ret[$key] = $rec->res;
		}
		return $ret;
	}

	public function exec($url)
	{
		return $this->process($url);
	}

	public function __destruct()
	{
		if ($this->_cookiePath !== null)
			$this->saveCookie($this->_cookiePath);
	}

	protected function applyDefaultHeader()
	{
		$this->setHeader(array(
			'accept' => '*/*',
			'accept-language' => 'zh-cn,zh',
			'connection' => 'Keep-Alive',
			'user-agent' => $this->getDefaultAgent(),
		));
	}

	private function getDefaultAgent()
	{
		$agent = 'Mozilla/5.0 (Compatible; ' . self::PACKAGE . '/' . self::VERSION . '; +Hightman) ';
		$agent .= 'php-' . php_sapi_name() . '/' . phpversion() . ' ';
		$agent .= php_uname('s') . '/' . php_uname('r');
		return $agent;
	}
}
