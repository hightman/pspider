PHP - spider 框架
===================

这是最近使用纯 `php` 代码开发的并行抓取(爬虫)框架，目前没有时间写文档，只简单标注如下。


使用 pspider
--------------

这里头的 URL 表管理需要 MySQLi 扩展支持，表结构和自定义的内容参见自定义文件。

1. 复制 `custom/skel.inc.php` 为 `custom/your.inc.php`
2. 根据说明修改 custom/your.inc.php
3. 根据 custom/your.inc.php 里的注释创建 mysql 的 URL 表
4. 运行 spider.php -u http://... 即可开始循环抓取
5. UrlTable 的实现很简单仅作示例，具体可自行重做


使用 HttpClient
----------------

其中 lib/HttpClient.class.php 可以单独使用，纯 PHP 实现的多 URL 并行抓取，
功能大体相当于 curl_multi?? 

支持回调，每处理完整一个请求就会立即调用。回调可以是函数也可以是实现了 `HttpParser`
接口的对象。原型如下：

```php

// 其中 $key 的值为并行抓取多个 URL 时具体的键值
function parse(HttpResponse $res, HttpRequest $req, mixed $key);

// 需要实现 HttpParser 接口
class myParser implements HttpParser
{
    public function parse(HttpResponse $res, HttpRequest $req, mixed $key);  
}

```

简单来个示范代码：

```php
require 'lib/HttpClient.class.php';

function test_cb($res, $req, $key)
{
   echo '[' . $key . '] url: ' . $req->getUrl() . ', ';
   echo 'time cost: ' . $res->timeCost . ', size: ' . number_format(strlen($res->body)) . "\n";
}

$http = new HttpClient('test_cb');

// 全部 URL 抓取完毕时一并返回，传入单个 URL 或数组组成的多个 URL
// 第一次请求可能因为域名解析等原因较慢，可以自行构造 HttpRequest 直接用 IP请求更快
$results = $http->get(array(
  'baidu' => 'http://www.baidu.com/',
  'sina' => 'http://news.sina.com.cn/',
  'google' => 'http://www.google.com.sg/',
  'qq' => 'http://www.qq.com/',
));

// 键名不变，值为 HttpResponse 对象
//print_r($results);

```

> 注意：您可以通过 HttpClient::debug('open'); 会详细打印很多信息。
