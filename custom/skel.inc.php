<?php
/**
 * pspider - custom template file
 * 
 * @author hightman <hightman@twomice.net>
 * @link http://www.hightman.cn/
 * @copyright Copyright &copy; 2008-2013 Twomice Studio
 */
/// --- custom 并发抓取数量
define('PSP_NUM_PARALLEL', 5);

/// --- custom 同一 URL 连续抓取间隔
define('PSP_CRAWL_PERIOD', 3600);

/**
 * 设置 MySQL 参数，要求带有 _urls 表，并采用以下结构：
  CREATE TABLE `_urls` (
  `id` varchar(32) NOT NULL COMMENT 'md5 hash of URL',
  `url` text,
  `rank` smallint NOT NULL default '0' COMMENT 'process prior level',
  `status` smallint NOT NULL default '0' COMMENT 'last http response status',
  `select_time` int unsigned NOT NULL default '0' COMMENT 'last process time',
  `update_time` int unsigned NOT NULL default '0' COMMENT 'last update time',
  PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='url table for pspider';
 */
class UrlTableCustom extends UrlTableMySQL
{

	public function __construct()
	{
		/// --- custom setting BEGIN
		$host = 'localhost';
		$user = 'root';
		$pass = '';
		$dbname = 'test';
		/// --- custom setting END

		parent::__construct($host, $user, $pass, $dbname);
		$this->test();
	}
}

/**
 * 自定义解析器
 */
class UrlParserCustom extends UrlParser
{

	/**
	 * 在这个方法内添加抓取内容解析处理代码
	 */
	public function parse($res, $req, $key)
	{
		parent::parse($res, $req, $key);
		if ($res->status === 200)
		{
			/// --- custom code BEGIN ---
			echo "PROCESSING: " . $req->getUrl() . "\n";
			/// --- custom code END ---
		}
	}

	/**
	 * 在这个方法内添加新 URL 过滤规则，主要是调用以下方法：
	 * followExternal()
	 * allowDomain(), disallowDomain()
	 * allow(), disallow(), disallowExt()
	 */
	public function defaultFilter()
	{
		parent::defaultFilter();
		/// --- custom filter BEGIN ---
		$this->followExternal(false);
		$this->disallow('.php?q=');
		/// --- custom filter END ---
	}
}
