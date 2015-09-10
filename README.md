PHP - spider 框架
===================

这是最近使用纯 `php` 代码开发的并行抓取(爬虫)框架，基于 [hightman\httpclient](https://github.com/hightman/httpclient) 组件。

您必须先装有 [composer](http://getcomposer.org)，然后在项目里先运行以下命令下载组件：

~~~
composer install
~~~


使用 pspider
--------------

这里头的 URL 表管理需要 MySQLi 扩展支持，表结构和自定义的内容参见自定义文件。

1. 复制 `custom/skel.inc.php` 为 `custom/your.inc.php`
2. 根据说明修改 custom/your.inc.php
3. 根据 custom/your.inc.php 里的注释创建 mysql 的 URL 表
4. 运行 spider.php -u http://... 即可开始循环抓取
5. UrlTable 的实现很简单仅作示例，具体可自行重做
