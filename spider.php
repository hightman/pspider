#!/usr/bin/env php
<?php
// PSPider start point (PHP 5.3+)
// Usage: spider.php -h
// ini setting
set_time_limit(0);
ini_set('display_errors', 'On');
ini_set('memory_limit', '1024M');

// parse options
if (php_sapi_name() !== 'cli') {
	echo "ERROR: The script can only be run under cli mode.\n";
	exit(-1);
}

$options = getopt('c:n:u:p:q:h');
if (isset($options['h']) || !isset($options['c'])) {
	echo <<<EOF
PSPider - PHP Spider (by hightman)

Usage: pspider -c <custom> [options]
       pspider -h

  -c <custom>      The name of custom file under 'custom/'
  -n <num>         The number of URLs to crawl in parallel
  -q <limit>       Quit after processing the number of URLs
  -p <seconds>     Time interval to crawl the same URL
  -u <url>         The start URL (forced to crawl once)
  -h               This help

EOF;
	exit(0);
}

$file = __DIR__ . '/custom/' . $options['c'] . '.inc.php';
if (!file_exists($file)) {
	echo "ERROR: Custom file dose not exists '$file'.\n";
	exit(-1);
}

// add library
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/UrlTable.php';
include $file;

if (!class_exists('UrlTableCustom') || !class_exists('UrlParserCustom')) {
	echo "ERROR: Invalid custom file '$file'.\n";
	echo "Must have defined class 'UrlParserCustom' inherited from 'UrlParser',\n";
	echo "and class 'UrlTableCustom' inherited from 'UrlTableMySQL'.\n";
	echo "Please see 'custom/skel.inc.php'.\n";
	exit(-1);
}

$options['n'] = isset($options['n']) ? intval($options['n']) : PSP_NUM_PARALLEL;
$options['p'] = isset($options['p']) ? intval($options['p']) : PSP_CRAWL_PERIOD;
$options['q'] = isset($options['q']) ? intval($options['q']) : 0;

// create objects
$ut = new UrlTableCustom;
$up = new UrlParserCustom($ut);
$http = new \hightman\http\Client($up);

// start url
if (isset($options['u'])) {
	$http->get($up->resetUrl($options['u']));
}

// loop to handle
$num = 0;
while ($urls = $ut->getSome($options['n'], $options['p'])) {
	if (count($urls) === 0) {
		break;
	}
	$http->mget($urls);
	$num += count($urls);
	if ($options['q'] > 0 && $num >= $options['q']) {
		break;
	}
	if ($num > $options['n'] && ($num % 1000) < $options['n']) {
		$up->stat(true);
	}
}

// print stats
$up->stat(true);
echo "OK, finished!\n";
