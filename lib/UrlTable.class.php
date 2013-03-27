<?php
/**
 * 多功能 URL 采集管理及解析器
 * 
 * @author hightman <hightman@twomice.net>
 * @link http://www.hightman.cn/
 * @copyright Copyright &copy; 2008-2013 Twomice Studio
 */

/**
 * URL 列表管理接口
 */
interface UrlTable
{
	/**
	 * 同一 URL 连续处理的时间间隔
	 */
	const DURATION = 3600;

	/**
	 * @return int URL 列表总个数
	 */
	public function getCount();

	/**
	 * @param int $duration 同一
	 * @return string 返回一个待处理的 URL，若无返回 null 出错则返回 false
	 */
	public function getOne($duration = self::DURATION);

	/**
	 * @param int $limit 
	 * @param int $duration 
	 * @return array 返回不超过指定个数的 URL 数组，若无返回空数组，出错则返回 false
	 */
	public function getSome($limit = 5, $duration = self::DURATION);

	/**
	 * @param string $url 要添加的 URL
	 * @param int $rank 被取出处理的优先级
	 * @return boolean 成功返回 true，若已存在或其它原因失败均返回 false
	 */
	public function addUrl($url, $rank = 0);

	/**
	 * @param string $url 要更新的 URL
	 * @param int $status URL 处理后的状态码
	 * @return boolean 成功返回 true， 失败返回 false
	 */
	public function updateUrl($url, $status = 200);

	/**
	 * @param string $url 要删除的 URL
	 * @return boolean 成功返回 true，失败返回 false
	 */
	public function delUrl($url);
}

/**
 * 基于 MySQLi 的 URL 列表管理，结构如下：
 * CREATE TABLE `_urls` (
 *   `id` varchar(32) NOT NULL COMMENT 'md5 hash of URL',
 *   `url` text NOT NULL,
 *   `rank` smallint(6) NOT NULL COMMENT 'process prior level',
 *   `status` smallint(6) NOT NULL COMMENT 'last http response status',
 *   `select_time` bigint(20) NOT NULL COMMENT 'last process time',
 *   `update_time` bigint(20) NOT NULL COMMENT 'last update time',
 *   PRIMARY KEY (`id`)
 * ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='url table for pspider';
 */
class UrlTableMySQL extends mysqli implements UrlTable
{
	private $_table = '_urls';
	private $_addCache = array();

	/** 	 
	 * @param string $name 设置数据库表名，默认 _urls
	 */
	public function setTableName($name)
	{
		$this->_table = $name;
	}

	public function getCount()
	{
		$res = $this->query('SELECT COUNT(*) AS count FROM ' . $this->_table);
		if ($res !== false)
		{
			$row = $res->fetch_assoc();
			$res->free();
			return $row['count'];
		}
		return 0;
	}

	public function getOne($duration = self::DURATION)
	{
		$urls = $this->getSome(1, $duration);
		if (!is_array($urls))
			return false;
		return count($urls) > 0 ? $urls[0] : null;
	}

	public function getSome($limit = 5, $duration = self::DURATION)
	{
		$now = time();
		$sql = 'SELECT id, url, ((' . $now . ' - select_time) * (rank + 1) / (status + 1)) AS score FROM ' . $this->_table . ' ';
		$sql .= 'WHERE select_time < ' . ($now - $duration) . ' '; // expired
		$sql .= 'OR (select_time > update_time AND select_time < ' . ($now - 300) . ') '; // failed
		$sql .= 'ORDER BY score DESC LIMIT ' . intval($limit);
		($fd = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . __CLASS__ . '.lock', 'w')) && flock($fd, LOCK_EX);
		if (($res = $this->query($sql)) === false)
			$ret = false;
		else
		{
			$ret = $ids = array();
			while ($row = $res->fetch_assoc())
			{
				$ids[] = $row['id'];
				$ret[] = $row['url'];
			}
			$res->free();
			if (count($ids) > 0)
			{
				$sql = 'UPDATE ' . $this->_table . ' SET select_time = ' . $now . ' ';
				$sql .= 'WHERE id IN (\'' . implode('\', \'', $ids) . '\')';
				$this->query($sql);
			}
		}
		$fd && flock($fd, LOCK_UN) && fclose($fd);
		return $ret;
	}

	public function addUrl($url, $rank = 0)
	{
		$id = md5($url);
		if ($this->inAddCache($id))
			return false;
		$url = $this->real_escape_string($url);
		$sql = 'INSERT INTO ' . $this->_table . ' (id, url, rank) ';
		$sql .= 'VALUES (\'' . $id . '\', \'' . $url . '\', ' . intval($rank) . ')';
		return $this->query($sql);
	}

	public function updateUrl($url, $status = 200)
	{
		$now = time();
		$sql = 'UPDATE ' . $this->_table . ' SET status = ' . intval($status) . ', update_time = ' . $now . ' ';
		$sql .= 'WHERE id = \'' . md5($url) . '\'';
		return $this->query($sql);
	}

	public function delUrl($url)
	{
		$sql = 'DELETE FROM ' . $this->_table . ' WHERE id = \'' . md5($url) . '\'';
		return $this->query($sql) && $this->affected_rows === 1;
	}

	public function query($query, $mode = MYSQLI_STORE_RESULT)
	{
		$this->ping();
		$res = parent::query($query, $mode);
		return $res;
	}

	protected function test()
	{
		if ($this->connect_error)
			return trigger_error($this->connect_error, E_USER_ERROR);
		$url = 'http://' . uniqid() . '.com/';
		if (!$this->addUrl($url))
			return trigger_error($this->error, E_USER_ERROR);
		$this->delUrl($url);
		return true;
	}

	private function inAddCache($id)
	{
		$now = time();
		if (isset($this->_addCache[$id]))
		{
			$this->_addCache[$id] = $now;
			return true;
		}
		$this->_addCache[$id] = $now;
		if (count($this->_addCache) > 20000)
		{
			$cache = array();
			$expire = $now - 3600;
			foreach ($this->_addCache as $key => $value)
			{
				if ($value > $expire)
					$cache[$key] = $value;
			}
			$this->_addCache = $cache;
		}
		return false;
	}
}

/**
 * 带 URL 提取功能的解析器基础类
 * 
 * 设置是 URL 过滤排除规则：
 * 规则语法支持局部字符串匹配，或正则匹配（必须是 # 开头）
 * 1. 若是默认允许的外站域名，则检测 disallowDomain 匹配一条则直接排除
 * 2. 若是默认不允许的外站域名，则检测 allowDomain，匹配任何一条则通过继续检测
 * 3. 检测 disallow 规则，匹配其中一条则立即排除
 * 4. 检测 allow 规则，若为空则直接通过，否则必须至少满足其中一条
 * 5. 检测 disallowExt 规则，匹配不允许的扩展名则直接排除
 * 6. 最终通过 ^-^
 */
class UrlParser implements HttpParser
{
	private $_timeBegin, $_numAdd, $_numUpdate, $_numFilter;
	private $_followExternal;
	private $_disallowDomain, $_allowDomain, $_disallow, $_allow;
	private $_allowRank;
	private $_disallowExt = array(
		'.tar' => true, '.gz' => true, '.tgz' => true, '.zip' => true, '.Z' => true, '.7z' => true,
		'.rpm' => true, '.deb' => true, '.ps' => true, '.dvi' => true, '.pdf' => true, '.smi' => true,
		'.png' => true, '.jpg' => true, '.jpeg' => true, '.bmp' => true, '.tiff' => true, '.gif' => true,
		'.mov' => true, '.avi' => true, '.mpeg' => true, '.mpg' => true, '.mp3' => true, '.qt' => true,
		'.wav' => true, '.ram' => true, '.rm' => true, '.rmvb' => true, '.jar' => true, '.java' => true,
		'.class' => true, '.diff' => true, '.doc' => true, '.docx' => true, '.xls' => true, '.ppt' => true,
		'.mdb' => true, '.rtf' => true, '.exe' => true, '.pps' => true, '.so' => true, '.psd' => true,
		'.css' => true, '.js' => true, '.ico' => true, '.dll' => true, '.bz2' => true, '.rar' => true,
	);
	private $_ut;

	/** 	
	 * @param UrlTable $ut 
	 */
	public function __construct(UrlTable $ut)
	{
		$this->_ut = $ut;
		$this->_timeBegin = time();
		$this->_numAdd = $this->_numUpdate = $this->_numFilter = 0;
		// apply default filters for extending
		$this->resetFilter();
		$this->defaultFilter();
	}

	public function __destruct()
	{
		$this->_ut = null;
	}

	/** 	 
	 * @return UrlTable
	 */
	public function getUrlTable()
	{
		return $this->_ut;
	}

	/**
	 * 扩展该类时在此应用默认的 URL 过滤规则
	 */
	public function defaultFilter()
	{
		
	}

	/**
	 * 重置所有过滤规则，但不包含后缀过滤规则
	 */
	public function resetFilter()
	{
		$this->_followExternal = false;
		$this->_disallowDomain = array();
		$this->_allowDomain = array();
		$this->_disallow = array();
		$this->_allow = array();
		$this->_allowRank = array();
	}

	/** 	 
	 * @param boolean $on 设置是否处理站外 URL，默认为 false
	 */
	public function followExternal($on = true)
	{
		$this->_followExternal = $on === true ? true : false;
	}

	/**
	 * @param string $rule 不允许的域名规则，支持正则表达式
	 */
	public function disallowDomain($rule)
	{
		$this->saveMatchRule($this->_disallowDomain, $rule);
	}

	/**
	 * @param string $rule 允许的域名规则，支持正则表达式
	 */
	public function allowDomain($rule)
	{
		$this->saveMatchRule($this->_allowDomain, $rule);
	}

	/**
	 * @param string $rule 不允许的 URL 规则，支持正则表达式
	 */
	public function disallow($rule)
	{
		$this->saveMatchRule($this->_disallow, $rule);
	}

	/**
	 * @param string $rule 允许的 URL 规则，支持正则表达式
	 * @param int $rank 匹配此规则的 URL 的权重值
	 */
	public function allow($rule, $rank = null)
	{
		$this->saveMatchRule($this->_allow, $rule);
		if ($rank !== null)
			$this->_allowRank[$rule] = intval($rank);
	}

	/**
	 * @param string $name 不允许的 URL 扩展名，必须以 . 开头
	 */
	public function disallowExt($name)
	{

		$this->_disallowExt[strtolower($name)] = true;
	}

	/**
	 * @param string $name 强制允许的 URL 扩展名，必须以 . 开头
	 */
	public function allowExt($name)
	{
		if (substr($name, 0, 1) === '.')
		{
			$name = strtolower($name);
			if (isset($this->_disallowExt[$name]))
				unset($this->_disallowExt[$name]);
		}
	}

	/**
	 * 打印或返回统计情况
	 * @param boolean $output 是否直接输出结果
	 */
	public function stat($output = false)
	{
		// time
		$time = time() - $this->_timeBegin;
		$string = date('m-d H:i:s') . ' - Time cost: ';
		if ($time > 3600)
		{
			$string .= intval($time / 3600) . ' hours ';
			$time %= 3600;
		}
		if ($time > 60)
		{
			$string .= intval($time / 60) . ' mins ';
			$time %= 60;
		}
		$string .= $time . ' secs, ';
		// stats
		$string .= sprintf('URLs total: %d, Add: %d, Update: %d, Filtered: %d', $this->_ut->getCount(), $this->_numAdd, $this->_numUpdate, $this->_numFilter);
		if ($output !== true)
			return $string;
		echo $string . "\n";
	}

	/**
	 * 实现 HttpParser 中定义的方法
	 * @param HttpResponse $res
	 * @param HttpRequest $req
	 * @param mixed $key
	 */
	public function parse($res, $req, $key)
	{
		// update url
		if ($this->_ut->updateUrl($req->getRawUrl(), $res->status))
			$this->_numUpdate++;
		// parse body
		if ($res->status === 200)
		{
			// get baseUrl
			$baseUrl = $req->getUrl();
			if (preg_match('/<base\s+href=[\'"]?(.*?)[\s\'">]/i', $res->body, $match))
				$baseUrl = $this->resetUrl($match[1], $baseUrl);
			// href="xxx", href='xxx'
			if (preg_match_all('/href=([\'"])(.*?)\1/i', $res->body, $matches) > 0)
			{
				foreach ($matches[2] as $url)
				{
					$this->processUrl($url, $baseUrl, $res->url);
				}
			}
			// href=xxx
			if (preg_match_all('/href=(?![\'"])(.*?)[\s>]/i', $res->body, $matches) > 0)
			{
				foreach ($matches[1] as $url)
				{
					$this->processUrl($url, $baseUrl, $res->url);
				}
			}
		}
		else if ($res->status === 301 || $res->status === 302)
		{
			$url = $this->resetUrl($res->getHeader('location'), $req->getUrl());
			$res->setHeader('location', $url); // overwrite formated url
			// save url for permanent redirection
			if ($res->status === 301)
				$this->processUrl($url, $res->url);
		}
	}

	/**
	 * @param string $url
	 * @param string $rawUrl 原先的开始页面 URL，用于计算是否为站外
	 * @param string &$rank
	 * @return boolean 是否 URL 符合过滤规则需要排除，需要排除返回 true
	 */
	public function isDisallow($url, $rawUrl = null, &$rank = null)
	{
		// get domain
		if (($pos1 = strpos($url, '://')) === false)
			return true;
		$pos1 += 3;
		$pos2 = strpos($url, '/', $pos1);
		$domain = $pos2 === false ? substr($url, $pos1) : substr($url, $pos1, $pos2 - $pos1);
		// external domain
		if ($rawUrl !== null && !@strstr($rawUrl, $domain))
		{
			// disallow domain
			if ($this->_followExternal && $this->isMatchRule($this->_disallowDomain, $domain))
				return true;
			// allow domain
			if (!$this->_followExternal
				&& (count($this->_allowDomain) === 0 || !$this->isMatchRule($this->_allowDomain, $domain)))
				return true;
		}
		// disallow
		if ($this->isMatchRule($this->_disallow, $url))
			return true;
		// allow
		if (count($this->_allow) > 0 && !$this->isMatchRule($this->_allow, $url, $rank))
			return true;
		// dislaowExt
		if (($pos1 = strpos($url, '?')) === false)
			$pos1 = strlen($url);
		if (($pos2 = strpos($url, '/', 8)) !== false
			&& ($ext = strrchr(substr($url, $pos2, $pos1 - $pos2), '.')))
		{
			$ext = strtolower($ext);
			if (isset($this->_disallowExt[$ext]))
				return true;
		}
		return false;
	}

	/**
	 * @param string $url
	 * @param string $baseUrl
	 * @return string 返回处理好的标准 URL 
	 */
	public function resetUrl($url, $baseUrl = null)
	{
		// 开头处理
		if (!strncasecmp($url, 'http://http://', 14))
			$url = substr($url, 7);
		if (strncasecmp($url, 'http://', 7) && strncasecmp($url, 'https://', 8))
		{
			if ($baseUrl === null)
				$url = 'http://' . $url;
			else
			{
				if (substr($url, 0, 1) === '/')
				{
					$pos = @strpos($baseUrl, '/', 8);
					$url = ($pos === false ? $baseUrl : substr($baseUrl, 0, $pos)) . $url;
				}
				else
				{
					$pos = @strrpos($baseUrl, '/', 8);
					$url = ($pos === false ? $baseUrl . '/' : substr($baseUrl, 0, $pos + 1)) . $url;
				}
			}
		}
		// 统一 URL 格式，顶级网址以 / 结尾，去除 # 后的锚点
		if (@strpos($url, '/', 8) === false)
			$url .= '/';
		if (($pos = strrpos($url, '#')) !== false)
			$url = substr($url, 0, $pos);
		// 计算并处理 '../../' 等多余的相对 URL
		if (strpos($url, '/./') !== false || strpos($url, '/../') !== false)
		{
			$parts = array();
			$tmpa = explode('/', substr($url, 8));
			for ($i = 0; $i < count($tmpa); $i++)
			{
				if ($tmpa[$i] === '.' || ($tmpa[$i] === '' && isset($tmpa[$i + 1])))
					continue;
				if ($tmpa[$i] !== '..')
					array_push($parts, $tmpa[$i]);
				else if (count($parts) > 1)
					array_pop($parts);
			}
			$url = substr($url, 0, 8) . implode('/', $parts);
		}
		return $url;
	}

	/**
	 * @return mixed
	 */
	protected function processUrl($url, $baseUrl, $rawUrl = null)
	{
		if (substr($url, 0, 1) === '#' || !strncasecmp($url, 'javascript:', 11) || !strncasecmp($url, 'mailto:', 7))
			return 'SKIP';
		$url = $this->resetUrl($url, $baseUrl);
		$rank = 0;
		if ($this->isDisallow($url, $rawUrl === null ? $baseUrl : $rawUrl, $rank))
		{
			$this->_numFilter++;
			return 'FILTER';
		}
		if ($this->_ut->addUrl($url, $rank))
		{
			$this->_numAdd++;
			return 'ADD';
		}
		return 'SKIP';
	}

	private function saveMatchRule(&$array, $rule)
	{
		if ($rule === null)
			$array = array();
		else if ($this->isRegexPattern($rule))
			array_push($array, "\xff" . $rule);
		else
			array_unshift($array, $rule);
	}

	private function isMatchRule($rules, $input, &$rank = null)
	{
		foreach ($rules as $rule)
		{
			if (ord($rule[0]) !== 0xff)
				$matched = stristr($input, $rule) !== false;
			else
			{
				$rule = substr($rule, 1);
				$matched = preg_match($rule, $input) > 0;
			}
			if ($matched === true)
			{
				if (isset($this->_allowRank[$rule]))
					$rank = $this->_allowRank[$rule];
				return true;
			}
		}
		return false;
	}

	private function isRegexPattern($input)
	{
		if (strlen($input) > 2 && $input[0] === '#')
		{
			for ($i = strlen($input) - 1; $i > 1; $i--)
			{
				if ($input[$i] === $input[0])
					return true;
				if ($input[$i] !== 'i' && $input[$i] !== 'u')
					break;
			}
		}
		return false;
	}
}
