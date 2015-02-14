<?php
include('lib/spyc.php');

/**
 * This is a super-quick script that exports the contents of your Etherpad Lite MySql store to
 * static HTML.  I created this because it was becoming a critical part of our infrastructure and
 * thus neeeded some kind of readable backup system.
 */

# load settings
define('VERSION', '0.2');
define('CONFIG_FILE', "settings.yml");
if(!file_exists(CONFIG_FILE)) {
  print("ERROR: you need to create a settings.yml, based on settings.yml.template\n");
  exit(1);
}
$config = Spyc::YAMLLoad(CONFIG_FILE);

# connect to db
try {
    $db = new PDO($config['db']['dsn'], $config['db']['username'], $config['db']['password'] );
}
catch(PDOException $e)
{
	echo $e->getMessage();
	die();
}

# print summary info
print("Starting eplite export (v".VERSION.")\n");
$results = $db->query("SELECT count(*) as total FROM store");
$row = $results->fetch(PDO::FETCH_NUM);
$total = $row[0];
print("  Found $total rows in the store\n");
$results = $db->query("SELECT count(*) as total FROM  `store` ".
    "WHERE  `key` NOT LIKE  '%:revs:%' AND  `key` LIKE  'pad:%' AND `key` NOT LIKE  '%:chat:%'");
$row = $results->fetch(PDO::FETCH_NUM);
$total = $row[0];
print("  Found $total unique pads in the store\n");

# helper function - start an html file
function start_html_file($file, $title) {
  fwrite($file,"<html>\n");
  fwrite($file,"<head>\n");
  fwrite($file,'<meta http-equiv="content-type" content="text/html;charset=utf-8"/>'."\n");
  fwrite($file,"<title>$title : Etherpad-Lite Export</title>");
  fwrite($file,"</head>\n");
  fwrite($file,"<body>\n");
}

# helper function - end an html file
function end_html_file($file){
  fwrite($file,"</html>\n");
  fwrite($file,"</body>\n");
}

# setup export dirs
$now = time();
$export_dirname = "eplite-backup";
if($config['timestamp']) {
    $export_dirname = $export_dirname . date("Ymd-His",$now);
}
$export_path = $config['backup_dir']."/".$export_dirname;
if(!file_exists($export_path)){
    mkdir($export_path);
}
$pad_export_path = $export_path."/pads";
if(!file_exists($pad_export_path)){
    mkdir($pad_export_path);
}
print ("  Exporting to $export_path\n");

# start the toc
$index = fopen($export_path."/index.html",'w');
start_html_file($index, "Backup Table Of Contents");
fwrite($index,"<h1>Backup Table of Contents</h1>");
fwrite($index,"<ul>\n");
$server_toc = fopen($export_path."/server-toc.html",'w');
start_html_file($server_toc, "Live Table Of Contents");
fwrite($server_toc,"<h1>Live Table of Contents</h1>");
fwrite($server_toc,"<ul>\n");


// replace linkify links: [[text]] to [[<a href='text.html'>text</a>]]
$linkify_replace = function ($matches) use ($db){
	// create an id form the link text:
	$url = str_replace(' ', '_', $matches[1]);
	$url = preg_replace('/\s+/', '_', $matches[1]);
	// fixes that links only seem to go until the #-character:
	$url = strpos($url, '#') !== false ? substr($url, 0, strpos($url, '#')) : $url;
	
	// check if exists:
	$q = $db->prepare("SELECT COUNT(*) FROM  `store` WHERE  `key` =  ?");
	$q->execute(array('pad:'.$url));
	$result = $q->fetch(PDO::FETCH_NUM);
	$exists = (bool) $result[0];

	// escape for definitive filename:
	$url = preg_replace('/[^(\x20-\x7F)]*/','', $url).".html";
	if($exists)
		return '[[<a href="'.$url.'">'.$matches[1].'</a>]]';
	else
		return '[[<a href="#" style="color:#990000">'.$matches[1].'</a>]]';
};

# go through all the pad master entries, saving the content of each
$results = $db->query("SELECT * FROM  `store` WHERE  `key` NOT LIKE  '%:revs:%' AND  `key` LIKE  'pad:%' AND `key` NOT LIKE  '%:chat:%' ORDER BY `key`");
foreach($results as $row){
  $title = str_replace("pad:","",$row['key']);
  $pad_value = json_decode($row['value']);
  $contents = $pad_value->atext->text;
  $contents = preg_replace_callback('/\[\[(.*?)\]\]/', $linkify_replace, $contents);
  # http://www.stemkoski.com/php-remove-non-ascii-characters-from-a-string/
  $filename = preg_replace('/[^(\x20-\x7F)]*/','', $title).".html";
  # add an item to the table of contents
  fwrite($index,"  <li><a href=\"pads/$filename\">$title</a></li>\n");
  fwrite($server_toc,"  <li><a href=\"".$config['base_url']."p/$title\">$title</a></li>\n");
  # export the contents too
  $pad_file = fopen($pad_export_path."/".$filename,'w');
  start_html_file($pad_file, $title);
  fwrite($pad_file,"<pre>\n");
  fwrite($pad_file,"$contents\n");
  fwrite($pad_file,"</pre>\n");
  end_html_file($pad_file);
  fclose($pad_file);
}

fwrite($index,"</ul>\n");
fwrite($server_toc,"</ul>\n");

# cleanup
end_html_file($index);
end_html_file($server_toc);
fclose($index);
fclose($server_toc);
// close connection:
$db = null;

print("Done\n");
