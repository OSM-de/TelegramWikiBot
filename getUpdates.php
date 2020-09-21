<?php
// Load composer
require_once __DIR__ . '/vendor/autoload.php';
use Longman\TelegramBot\Request;

// Load config that shouldn't be sync'd to GitHub
require_once __DIR__ . '/config.php';

// Preferred Language
$pref_lang    = 'de';

$sent_messages = json_decode(file_get_contents("sent_messages.json"));
$old_message = "";

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
		
		if (isset($this_update->edited_message)) {
			foreach($sent_messages->sent_messages as $m) {
				if ($m->chat_id == $chat_id && $m->reply_to_message_id == $message_id) {
				$old_message = $m->text;
				}
			}
		}
		
		// ... typing while I resolve all those links
		/* 2020-07-09 removing Chat Action because it's annoying people.
		Request::sendChatAction([
			'chat_id' => $chat_id,
			'action'  => Longman\TelegramBot\ChatAction::TYPING,
		]);
		*/

		// Go through code/pre text entities 
		$ent_array_in_msg = Array();
		if (isset($this_message->entities)) {
			foreach($this_message->entities as $ent) {
				if ($ent->type == "code") {
					$this_offset = intval($ent->offset);
					if (strpos(substr($this_message->text, $this_offset, $ent->length), " ") !== false) {
						$this_offset += strpos(substr($this_message->text, $this_offset, $ent->length), " ") + 1;
					}
					$ent_array_in_msg[] = Array($this_offset, intval($ent->length));
				}
			}
			foreach($ent_array_in_msg as $ent) {
				$this_resolved = resolveOSMWikiLinks(trim(substr($this_message->text, $ent[0], $ent[1])));
				if (strpos($send_text, $this_resolved) === false) {
					$send_text .= ($send_text != "" ? "\n" : "") . $this_resolved;
				}
			}
		}

		// Get other wiki links
		$send_text .= ($send_text != "" ? "\n" : "") . resolveLinks($this_message->text);
		
		$send_text = preg_replace("/[\r?\n]+/", "\n", $send_text);
		
		// sending the result back to the chat
		if ($send_text != $old_message) {
			try {
				$send_result = Request::sendMessage([
					'chat_id'				=> $chat_id,
					'reply_to_message_id'	=> $message_id,
					'parse_mode'			=> 'MarkdownV2',
					'text'					=> $send_text
				]);
				$this_result = Array('chat_id' => $chat_id, 'reply_to_message_id' => $message_id, 'text' => $send_text);
				$sent_messages->sent_messages[] = $this_result;
				file_put_contents("sent_messages.json", json_encode($sent_messages));
			} catch (Longman\TelegramBot\Exception\TelegramException $e) {
				syslog(LOG_INFO, $e->getMessage());
			}
		}

	} else {
		echo $server_response->printError();
	}
} catch (Longman\TelegramBot\Exception\TelegramException $e) {
	syslog(LOG_INFO, $e->getMessage());
} catch (Longman\TelegramBot\Exception\TelegramLogException $e) {
	syslog(LOG_INFO, $e->getMessage());
}

function resolveLinks($text) {
	global $pref_lang;
	$returntext = "";
	// Possible matches for other wiki-objects:
	// Q1234   : DataItems of openstreetmap.org
	// W:Q1234 : wikidata.org
	// [[Tag]] : wiki.openstreetmap.org without pre-processing
	// [[W:A]] : <preferredLanguage>.wikipedia.org
	// (C|N|W|R)(#|/)1234 : Changeset, Node, Way, Relation #1234
	preg_match_all("/((W:)?Q(\d+)(\#P(\d+))?|\[\[(W:)?([^\]]+)\]\])/", $text, $matches);
	for($match_num = 0; $match_num < sizeof($matches[0]); $match_num++) {
		if ($matches[3][$match_num] != "" && $matches[2][$match_num] == "") {
			$wiki = "wiki.openstreetmap.org";
			$title = "Item:Q" . $matches[3][$match_num];
			$src = "OSM DataItems";
		}
		if ($matches[3][$match_num] != "" && $matches[2][$match_num] == "W:") {
			$wiki = "wikidata.org";
			$title = "Q" . $matches[3][$match_num];
			$src = "WikiData";
		}
		if ($matches[7][$match_num] != "" && $matches[6][$match_num] == "") {
			$wiki = "wiki.openstreetmap.org";
			$title = str_replace(" ", "_", $matches[7][$match_num]);
			$src = "OSM Wiki";
		}
		if ($matches[7][$match_num] != "" && $matches[6][$match_num] == "W:") {
			$wiki = $pref_lang . ".wikipedia.org";
			$title = str_replace(" ", "_", $matches[7][$match_num]);
			$src = "Wikipedia " . strtoupper($pref_lang);
		}
		$page = wikiApiGetPage($wiki, $title);
		// 2. try for wikipedia without preferred language
		if ($page == "" && $wiki == $pref_lang . ".wikipedia.org") {
			$wiki = "en.wikipedia.org";
			$page = wikiApiGetPage($wiki, $title);
			$src = "Wikipedia EN";
		}
		$this_link = "";
		if ($page != "") {
			$this_link = "(" . $wiki . "/wiki/" . str_replace(Array("%2f", "%2F", "+"), Array("/", "/", "_"), urlencode($page)) . 
				($matches[4][$match_num] != "" ? $matches[4][$match_num] : "") . 
				")";
		}
		if ($page != "" && strpos($returntext, $this_link) === false) {
			$returntext .= 
				($returntext != "" ? "\n" : "") .
				buildMarkdownLine($matches[0][$match_num], $page, $this_link, $src);
		}
	}
	preg_match_all("/([CNWR])[\#|\/](\d+)/i", $text, $matches);
	$link_abbr = Array("C" => "Changeset", "N" => "Node", "W" => "Way", "R" => "Relation");
	for($match_num = 0; $match_num < sizeof($matches[0]); $match_num++) {
		$title = getNWRInfo(strtolower($link_abbr[strtoupper($matches[1][$match_num])]), $matches[2][$match_num]);
		$returntext .=
			($returntext != "" ? "\n" : "") . $title;
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
	preg_match_all("/([\w\d\:\_]*)=?([^ +]*)/", $text, $matches);
	for($match_num = 0; $match_num < sizeof($matches[0]); $match_num++) {
		$lookup = "";
		// If value is empty lookup the key in the preferred language first
		if (trim($matches[2][$match_num]) == "" || trim($matches[2][$match_num]) == "*") {
			$lookup = "Key:" . urlencode($matches[1][$match_num]);
		} else {
			if (trim($matches[0][$match_num]) != "") {
				// if value is set lookup the key=value combination
				$lookup = "Tag:" . urldecode($matches[0][$match_num]);
			}
		}
		if ($lookup != "") {
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

			$this_link = "";
			if ($page != "") {
				$this_link = "(" . $wiki . "/wiki/" . str_replace(Array("%2f", "%2F", "+"), Array("/", "/", "_"), urlencode($page)) . ")";
			}
			if ($page != "" && strpos($returntext, $this_link) === false) {
				$returntext .= 
					($returntext != "" ? "\n" : "") . 
					buildMarkdownLine($matches[0][$match_num], $page, $this_link);
			}
		}
	}
	return $returntext;
}

function buildMarkdownLine($title, $page, $link, $src = null) {
	if (trim($title) != "") {
		return 
			replaceMarkdownCharacters($title) . 
			($src != null ? " \(" . $src . "\)" : "").
			": [" . 
			replaceMarkdownCharacters($page) .
			"]" . $link;
	}
}

function replaceMarkdownCharacters($instr) {
	return str_replace(
		Array("=", "*", "[", "]", "#", "_", "|", ".", "(", ")"),
		Array("\\=", "\\*", "\\[", "\\]", "\\#", "\\_", "\\|", "\\.", "\\(", "\\)"),
		$instr
	);
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

function getNWRInfo($type, $id) {
	$url = "https://api.openstreetmap.org/api/0.6/".$type."/".$id.".json";
	$fc = file_get_contents($url);
	$j = json_decode($fc);
	$this_element = $j->elements[0];
	if ($type != "changeset") {
		$c_time = strtotime($this_element->timestamp);
		$dt = new DateTime("@$c_time");
		$dtp = strval($dt->format("d\\\.m\\\.Y \\u\\m H:i:s"));
		$title = "[" . strtoupper(substr($type, 0, 1)) . substr($type, 1) . " " .
			(isset($this_element->tags->name) ? "\"" . replaceMarkdownCharacters($this_element->tags->name) . "\"" : $this_element->id) . 
			"](https://osm.org/$type/$id), geändert am " . $dtp . " " .
			"zu Version " . $this_element->version . " mit [Changeset " . $this_element->changeset . "](https://osm.org/changeset/{$this_element->changeset}) von " .
			"Benutzer [".replaceMarkdownCharacters($this_element->user)."](https://osm.org/user/{$this_element->user}})";
	} else {
		$c_time = strtotime($this_element->created_at);
		$dt = new DateTime("@$c_time");
		$dtp = strval($dt->format("d\\\.m\\\.Y \\u\\m H:i:s"));
		$title = "[Changeset " . $this_element->id . "](https://osm.org/changeset/$id) " . 
			"erstellt von Benutzer [".replaceMarkdownCharacters($this_element->user)."](https://osm.org/user/{$this_element->user}}) ".
			"am " . $dtp;
	}
	return $title;
}
?>
