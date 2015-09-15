<?php
/**
 * 多功能字符串工具
 *
 * @author hightman <hightman@twomice.net>
 * @link http://www.hightman.cn/
 * @copyright Copyright &copy; 2008-2013 Twomice Studio
 */

/**
 * String Helper (all are static function)
 *
 * <pre>
 * StringHelper::decodeHtml($html);
 * StringHelper::fixHtmlCharset($html, $charset = 'utf-8');
 * StringHelper::finds($buf, $tag1, $tag2[, ...]);
 * StringHelper::find($buf, $tag1, $tag2[, ...]);
 * StringHelper::contains($buf, $tokens);
 * </pre>
 */
class StringHelper
{

	/**
	 * @param string $html
	 * @return string 解码后的 html
	 */
	public static function decodeHtml($html)
	{
		if (strpos($html, '<') !== false) {
			$html = strip_tags($html); /* preg_replace('/<.+?>/u', '', $html); */
		}
		return html_entity_decode(trim($html), ENT_QUOTES, 'utf-8');
	}

	/**
	 * @param string $charset 目标字符集，默认 utf-8
	 * @return string 强制转换网页内容为目标字符集
	 */
	public static function fixHtmlCharset($html, $charset = 'utf-8')
	{
		if (preg_match('/charset=["\']?([0-9a-zA-Z_-]+)/', $html, $match)
			&& (strncasecmp($charset, 'gb', 2) || strncasecmp($match[1], 'gb', 2))
			&& strcasecmp($charset, $match[1])) {
			if (!strcasecmp($match[1], 'gb2312')) {
				$match[1] = 'gbk';
			}
			if (function_exists('iconv')) {
				return iconv($match[1], $charset . '//IGNORE', $html);
			} elseif (function_exists('mb_convert_encoding')) {
				return mb_convert_encoding($html, $charset, $match[1]);
			}
		}
		return $html;
	}

	/**
	 * 根据标记快速查找字符串列表
	 * @param string $buf
	 * @param array $config
	 * array(
	 *   array(key1, arg1, arg2, ...),
	 *   array(key2, arg1, arg2, ...),
	 * ),
	 * @return array
	 * @see StringMatcher::find
	 */
	public static function finds($buf, $config, &$error = null)
	{
		$obj = new StringMatcher($buf);
		return $obj->finds($config, $error);
	}

	/**
	 * 根据标记快速查找字符串
	 * @param string $buf
	 * @return string 返回最后两个标记之间的内容，找不到返回 null
	 * @see StringMatcher::find
	 */
	public static function find($buf)
	{
		$args = func_get_args();
		array_shift($args);
		$obj = new StringMatcher($buf);
		return call_user_func_array(array($obj, 'find'), $args);
	}

	/**
	 * 判断字符串是否包含数组中的字符串
	 * @param string $buf 源字符串
	 * @param array $tokens 字符串标记列表
	 * @return boolean
	 */
	public static function contains($buf, $tokens)
	{
		foreach ($tokens as $token) {
			if (strpos($buf, $token) !== false) {
				return true;
			}
		}
		return false;
	}
}

/**
 * StringMatcher to parse data
 */
class StringMatcher
{
	private $_buf, $_pos;

	/**
	 * @param string $buf
	 */
	public function __construct($buf)
	{
		$this->_buf = $buf;
		$this->_pos = 0;
	}

	/**
	 * 批量查找
	 * @param array $config
	 * array(
	 *   array(key1, arg1, arg2, ...),
	 *   array(key2, arg1, arg2, ...),
	 * ),
	 * @param string $error optional reference
	 * @return array
	 */
	public function finds($config, &$error = null)
	{
		$ret = array();
		foreach ($config as $args) {
			$key = array_shift($args);
			$val = call_user_func_array(array($this, 'find'), $args);
			if ($val === null || $val === false) {
				$error = 'Cannot find `' . $key . '\': ' . implode(' ... ', $args);
				$pos = strrpos($error, '...');
				$error = substr_replace($error, '???', $pos, 3);
				continue;
				//return false;
			}
			$ret[$key] = $val;
		}
		return $ret;
	}

	/**
	 * 根据特征查找字符串，不定参数：
	 * 起始1，起始2，起始3 ... 结束关键
	 * 新增支持特殊串
	 * "$$$..."，表示后面的字符串必须在这个字符串之前，以免跨越太大
	 * "^^^..."，表示后面的字符串如果在这个串之前就用采用当前串的位置
	 * @return string 成功返回区间内的字符串并将位置设在本字符串之末，若找不到返回 null
	 */
	public function find()
	{
		$args = func_get_args();
		$cnt = count($args);
		if ($cnt < 2) {
			return trigger_error(__CLASS__ . '::find() expects at least 2 parameters, ' . $cnt . ' given', E_USER_WARNING);
		}
		for ($end = $pre = false, $pos1 = $this->_pos, $i = 0; $i < ($cnt - 1); $i++) {
			if (substr($args[$i], 0, 3) === '$$$') {
				$end = strpos($this->_buf, substr($args[$i], 3), $pos1);
			} elseif (substr($args[$i], 0, 3) === '^^^') {
				$pre = strpos($this->_buf, substr($args[$i], 3), $pos1);
			} else {
				$pos1 = strpos($this->_buf, $args[$i], $pos1);
				if ($pos1 === false) {
					return null;
				} elseif ($end !== false && $pos1 > $end) {
					return '';
				}
				if ($pre !== false) {
					if ($pos1 > $pre) {
						$pos1 = $pre;
					}
					$pre = false;
				}
				$pos1 += strlen($args[$i]);
			}
		}
		if (($pos2 = strpos($this->_buf, $args[$i], $pos1)) !== false) {
			if ($end !== false && $pos2 > $end) {
				return '';
			}
			if ($pre !== false) {
				if ($pos2 > $pre) {
					$pos2 = $pre;
				}
				$pre = false;
			}
			$this->_pos = $pos2;
			return substr($this->_buf, $pos1, $pos2 - $pos1);
		}
		return null;
	}

	/**
	 * 移动当前处理位置位置指针，类似 fseek
	 * @param int $offset
	 * @param int $whence 可选值：SEEK_SET/SEEK_CUR/SEEK_END
	 */
	public function seek($offset, $whence = SEEK_CUR)
	{
		$offset = intval($offset);
		switch ($whence) {
			case SEEK_SET:
				$this->_pos = $offset;
				break;
			case SEEK_END:
				$this->_pos = $offset + strlen($this->_buf);
				break;
			case SEEK_CUR:
			default:
				$this->_pos += $offset;
				break;
		}
		return $this->_pos;
	}
}
