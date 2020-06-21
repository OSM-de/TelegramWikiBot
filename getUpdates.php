<?php
// Load composer
require_once __DIR__ . '/vendor/autoload.php';

use Longman\TelegramBot\Request;

// Add you bot's API key and name
$bot_api_key  = 'ITS_A_SECRET';
$bot_username = 'OSMdeWikiBot';

// Preferred Language
$pref_lang    = 'de';

// Define all IDs of admin users in this array (leave as empty array if not used)
$admin_users = [
	77720808
];

try {
	// Initialize Bot core
	$telegram = new Longman\TelegramBot\Telegram($bot_api_key, $bot_username);
	$telegram->enableAdmins($admin_users);
	$telegram->enableLimiter();

	// Handle updates
	$telegram->useGetUpdatesWithoutDatabase();
	$server_response = $telegram->handle();
	$entityBody = file_get_contents('php://input');

	if ($server_response) {
		$send_text = "";
		$this_update = json_decode($entityBody);

		// If a message was edited, we'll just send out another message with the new content
		$this_message = (isset($this_update->edited_message) ? $this_update->edited_message : $this_update->message);
		
		// Chat and Message ID for later use
		$chat_id = $this_message->chat->id;
		$message_id = $this_message->message_id;
		
		// ... typing while I resolve all those links
		Request::sendChatAction([
			'chat_id' => $chat_id,
			'action'  => Longman\TelegramBot\ChatAction::TYPING,
		]);
		
		// Go through code/pre text entities 
		if (isset($this_message->entities)) {
			foreach($this_message->entities as $ent) {
				if ($ent->type == "code" || $ent->type == "pre") {
					$send_text .= resolveOSMWikiLinks(substr($this_message->text, $ent->offset, $ent->length));
				}
			}
		}
		
		// Get other wiki links
		$send_text .= ($send_text != "" ? "\n" : "") . resolveLinks($this_message->text);

		// sending the result back to the chat
		$send_result = Request::sendMessage([
			'chat_id'				=> $chat_id,
			'reply_to_message_id'	=> $message_id,
			'parse_mode'			=> 'MarkdownV2',
			'text'					=> $send_text
		]);
	} else {
		echo $server_response->printError();
	}
} catch (Longman\TelegramBot\Exception\TelegramException $e) {
	echo $e->getMessage();
} catch (Longman\TelegramBot\Exception\TelegramLogException $e) {
	echo $e->getMessage();
}

function resolveLinks($text) {
	global $pref_lang;
	$returntext = "";
	// Possible matches for other wiki-objects:
	// Q1234 :   Wikidata of openstreetmap.org
	// W:Q1234 : wikidata.org
	// [[Tag]] : wiki.openstreetmap.org without pre-processing
	// [[W:A]] : <preferredLanguage>.wikipedia.org
	preg_match_all("/((W:)?Q(\d+)(\#P(\d+))?|\[\[(W:)?([^\]]+)\]\])/", $text, $matches);
	for($match_num = 0; $match_num < sizeof($matches[0]); $match_num++) {
		if ($matches[3][$match_num] != "" && $matches[2][$match_num] == "") {
			$wiki = "wiki.openstreetmap.org";
			$title = "Item:Q" . $matches[3][$match_num];
			$src = "OSM WikiData";
		}
		if ($matches[3][$match_num] != "" && $matches[2][$match_num] == "W:") {
			$wiki = "wikidata.org";
			$title = "Q" . $matches[3][$match_num];
			$src = "WikiData";
		}
		if ($matches[7][$match_num] != "" && $matches[6][$match_num] == "") {
			$wiki = "wiki.openstreetmap.org";
			$title = $matches[7][$match_num];
			$src = "OSM Wiki";
		}
		if ($matches[7][$match_num] != "" && $matches[6][$match_num] == "W:") {
			$wiki = $pref_lang . ".wikipedia.org";
			$title = $matches[7][$match_num];
			$src = "Wikipedia " . strtoupper($pref_lang);
		}
		$page = wikiApiGetPage($wiki, $title);
		// 2. try for wikipedia without preferred language
		if ($page == "" && $wiki == $pref_lang . ".wikipedia.org") {
			$wiki = "en.wikipedia.org";
			$page = wikiApiGetPage($wiki, $title);
			$src = "Wikipedia EN";
		}
		if ($page != "") {
			$returntext .= 
				($returntext != "" ? "\n" : "") . 
				str_replace(
					Array("=", "*", "[", "]", "#"), 
					Array("\\=", "\\*", "\\[", "\\]", "\\#"), 
					$matches[0][$match_num]
				) . 
				" \(" . $src . "\) : [" . 
				str_replace("=", "\\=", $page) . 
				"](" . $wiki . "/wiki/" . urlencode($page) . 
				($matches[4][$match_num] != "" ? $matches[4][$match_num] : "") . 
				")";
		}
	}
	return $returntext;
}
function resolveOSMWikiLinks($text) {
	global $pref_lang;
	$wiki = "wiki.openstreetmap.org";
	$returntext = "";
	// Cleaning up if multiline text
	$text = trim(str_replace(Array("\r","\n"), Array(" ", " "), $text));
	// Match OSM-de specific tagging
	preg_match_all("/([\w\d\:\_]*)=([^ +]*)/", $text, $matches);
	for($match_num = 0; $match_num < sizeof($matches[0]); $match_num++) {
		// If value is empty lookup the key in the preferred language first
		if (trim($matches[2][$match_num]) == "" || trim($matches[2][$match_num]) == "*") {
			$lookup = "Key:" . urlencode($matches[1][$match_num]);
		} else {
			// if value is set lookup the key=value combination
			$lookup = "Tag:" . urldecode($matches[0][$match_num]);
		}
		
		// 1. Try : Lookup with preferred language
		$page = wikiApiGetPage($wiki, strtoupper($pref_lang) . ":" . $lookup);
		// 2. Try : Lookup with standard language
		if ($page == "")
			$page = wikiApiGetPage($wiki, $lookup);
		// 3. Try : If lookup was Tag:... try with the Key only in preferred language
		if ($page == "")
			$page = wikiApiGetPage($wiki, strtoupper($pref_lang) . ":" . str_replace("Tag:", "Key:", substr($lookup, 0, strpos($lookup, "="))));
		// 4. Try : If lookup was Tag:... try with the Key only in standard language
		if ($page == "")
			$page = wikiApiGetPage($wiki, str_replace("Tag:", "Key:", substr($lookup, 0, strpos($lookup, "="))));
		
		if ($page != "") {
			$returntext .= 
				($returntext != "" ? "\n" : "") . 
				str_replace(
					Array("=", "*"),
					Array("\\=", "\\*"), 
					$matches[0][$match_num]
				) . 
				": [" . 
				str_replace("=", "\\=", $page) . 
				"](" . $wiki . "wiki/".urlencode($page) . 
				")";
		}
	}
	return $returntext;
}
function wikiApiGetPage($wiki, $query) {
	global $pref_lang;
	// Use MediaWiki API to check if the title exists or
	// if it redirects to another page. Returns empty if title does
	// not exist.
	if (trim($query) != strtoupper($pref_lang) . ":" && trim($query) != "") {
		$url = "https://" . $wiki . "/w/api.php?action=query&format=json&meta=&continue=&titles=" . $query . "&redirects";
		$api_result = file_get_contents($url);
		$json = json_decode($api_result);
		if ($json != null) {
			if (isset($json->query->pages->{-1})) {
				$return_page = ""; 
			} else {
				foreach($json->query->pages as $p) {
					$return_page = strval($p->title);
				}
			}
		}
	} else $return_page = "";
	return $return_page;
}
?>