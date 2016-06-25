<?php

class IRCBot {
	public $botnick = "dummy";
	public $messageHandler = array();
	public $ircMessageHandler = array();
	public $error = "No Error";
	public $online = true;
	public $channel = "#PhoenixRising";
	public $chats = array();
	public $pinged = false;
	public $db = false;
	public $nextid = 0;
	public $index = 0;
	public $quotesbyurn = array();
	public $quotesbyindex = array();
	public $users = array();
	public $tag = null;
	public $chatlog = array();
	public $dbs = array();
	public $dbname = "quote";
	public $fp = false;
	public $log = false;
	public $quiet = false;
	public $count = 0;
	public $count30 = 0;
	
	function __construct($botnick) {
		$this->botnick = $botnick;
		$this->dbs = ["quote"=>"quotes.txt"];
		$this->users = ['node'=>1, 'jedi'=>1, 'savagegreenboro'=>1, 'yottzumm'=>1, 'gimpish'=>1, 'ren'=>1];
		$this->setup();
	}

	function loadTaoTe() {
		$doc = new DOMDocument();
		$doc->loadHTMLFile("taote.htm");
		$xpath = new DOMXpath($doc);

		$section = 1;
		do {
			$nodes = $xpath->evaluate("
		//text()[preceding::b[text()='".$section."'] and following::b[text()='".($section+1)."']]
				");

			$msg = "";
			foreach ($nodes as $node) {
				$msg .= $node->nodeValue;
				$msg .= " ";
			}
			$msg = trim(preg_replace('/\s\s+/', ' ', $msg));
			$this->addRecord("$section|Tao$section|Lao-tzu|#tao|$msg");
			$section++;
		} while($section < 82);
	}

	function reloadDB() {
		if ($this->dbname && $this->dbs[$this->dbname]) {
			$this->db = fopen($this->dbs[$this->dbname], "r+");
			$this->nextid = 0;
			$this->index = 0;
			$this->quotesbyurn = array();
			$this->quotesbyindex = array();
			while ($this->db && ($record = fgets($this->db, 8192)) != FALSE) {
				$this->addRecord($record);
			}
			if ($this->db) {
				fclose($this->db);
			}
			$this->loadTaoTe();
		}
	}

	function SaveToDB($record) {
		if ($this->addRecord($record) && $this->dbname && $this->dbs[$this->dbname]) {
			$this->db = fopen($this->dbs[$this->dbname], "a");
			fputs($this->db, "$record\r\n");
			fclose($this->db);
			return true;
		} else {
			return false;
		}
	}

	function addRecord($record) {
		$fields = explode("|", $record);
		# only add a record if it appears valid
		if (strlen($record) > 9 && count($fields) == 5) {
			if ($fields[0] >= $this->nextid) {
				$this->nextid = $fields[0]+1;
			}
			$this->quotesbyurn[$fields[1]] = $fields;
			$this->quotesbyindex[$this->index] = $fields;
			$this->index++;
			return true;
		} else {

			return false;
		}
	}
	public function OnPrivMsg($name, $func) {
		$this->messageHandler[$name] = $func;
	}
	public function on($name, $func) {
		$this->ircMessageHandler[$name] = $func;
	}
	public function Connect($logfile, $server, $channel = false) {
		$this->log = fopen($logfile, "a+");
		$this->reloadDB();
		if ($channel) $this->channel = $channel;
		$this->fp = stream_socket_client($server, $errno, $errstr, 30);
		if (!$this->fp) {
			$this->error = "$errstr ($errno)";
			return null;
		}
		stream_set_timeout($this->fp, 1);
		$this->Emit("USER {$this->botnick} 0 * :{$this->botnick}\r\n");
		$this->Emit("NICK {$this->botnick}\r\n");
		return $this;
	}
	function Disconnect() {
		fclose($this->fp);
	}

	function checkForMessages() {
		if ($this->online && !feof($this->fp)) {
			$cmd = fgets($this->fp, 1024);

			$info = stream_get_meta_data($this->fp);

			if($info['timed_out']) {
				return true;
			}
			fwrite($this->log, "$cmd\n");
			$array = explode(" ", $cmd, 4);
			$message = $array[1];
			if ($array[0] == "PING") {
				$this->Emit("PONG {$array[1]}\r\n");
				if (!$this->pinged) $this->Rejoin();
				$this->pinged = true;
				if (!$this->quiet && ($this->count > 8 || $this->count30 > 15)) {
					$this->RandomQuote($this->tag);
					$this->count = 0;
					$this->count30 = 0;
				} else {
					$this->count++;
					$this->count30++;
				}
			} else if (isset($this->ircMessageHandler[$message])) {
				$this->ircMessageHandler[$message]($this, $array, $cmd);
			} else {
				# echo "$message not set\r\n";
			}
				
			return true;
		} else {
			return false;
		}
	}
	function Syntax() {
		return "Failed!  Syntax of database insert is: Uniform_Resource_Name|nickname|tag|quote, like: quote://penny_saved|Benjamin_Franklin|#economy|A penny saved is a penny earned.";
	}
	function Capture($from, $args) {
		if ($args[0]) {
			$record = implode(" ", $args);
			$expanded = explode("|", $record);
			if (count($expanded) == 4) {
				$lines = $expanded[3];
				$user = $expanded[1];
				$len = count($this->chatlog[$user]);
				if ($len > 0) {
					if ($len < $lines) {
						$lines = $len;
					}
					$expanded[3] = implode(" ", array_slice($this->chatlog[$user],  $len - $lines, $lines));
					$record = "$this->nextid|".implode("|", $expanded);
					if ($this->SaveToDB($record)) {
						$this->DelaySend($from, "Captured: $record into $this->dbname");
					} else {
						$this->DelaySend($from, $this->Syntax()." Not $record");
					}
				} else if ($user && $this->channel) {
					$this->DelaySend($this->channel, "I'm looking for a succinct quote from $user.");
				} else {
					$this->DelaySend($from, $this->Syntax(). " No chatting?");
				}
			} else {
				$this->DelaySend($from, $this->Syntax()." Wrong number of fields in $record?");
			}
		} else {
			$this->DelaySend($from, $this->Syntax()." No parameters?");
		}
	}
	function Insert($from, $args) {
		if ($args[0]) {
			$record = "$this->nextid|".implode(" ", $args);
			if ($this->SaveToDB($record)) {
				$this->DelaySend($from, "Remembered: $record into $this->dbname");
			} else {
				$this->DelaySend($from, $this->Syntax());
			}
		} else {
			$this->DelaySend($from, $this->Syntax());
		}
	}
	function UrnQuote($from, $args) {
		if ($args[0] && $this->channel) {
			$this->quiet = false;
			$quote = $this->quotesbyurn[$args[0]];
			if ($quote) {
				$this->DelaySend($this->channel, "($quote[1]): $quote[2] said on $quote[3]: $quote[4]");
			} else {
				$this->DelaySend($from, "Failed!  Specify a Uniform Resource Name, not '$args[0]', like quote://penny_saved and don't forget to join a channel");
			}
		} else {
			$this->DelaySend($from, "Failed!  Specify a Uniform Resource Name, like quote://penny_saved and don't forget to join a channel");
		}
	}
	function RandomQuote($tag) {
		$this->quiet = false;
		$this->tag = $tag;
		foreach ($this->quotesbyindex as $q) {
			$quote = $this->quotesbyindex[rand(1, $this->index-1)];
			if ($this->channel && ($this->tag == null || $quote[3] == $this->tag)) {
				$this->DelaySend($this->channel, "($quote[1]): $quote[2] said on $quote[3]: $quote[4]");
				break;
			}
		}
	}
	function Rejoin() {
		if ($this->channel) {
			$this->Emit("JOIN {$this->channel}\r\n");
		}
		foreach($this->chats as $chat) {
			$this->Emit("JOIN $chat\r\n");
		}
	}
	function Account($from) {
		$nick_array = preg_split("/[:!@\.]/", $from);
		if (trim($nick_array[3]) != "") {
			return $nick_array[3];
		} else {
			return $nick_array[1];
		}
	}
	function Nick($from) {
		$nick_array = preg_split("/[:!@\.]/", $from);
		if (trim($nick_array[0]) != "") {
			return $nick_array[0];
		} else {
			return $nick_array[1];
		}
	}
	function ProcessMessage($array) {
		print_r($array);
		$from = array_shift($array);
		$nick = $this->Nick($from);
		echo "Nick is $nick\r\n";
		$account = $this->Account($from);
		echo "Account is $account\r\n";
		$irccmd = array_shift($array);
		$to = array_shift($array);
		$capturedMessage = implode(" ", $array);
		$args = explode(" ", trim(array_shift($array)));
		$Message = trim(substr(array_shift($args), 1));
		$message = strtolower($Message);
		$this->count = 0;
		if (isset($this->messageHandler[$message])) {
			$this->messageHandler[$message]($this, $nick, $account, $to, $args);
		} else {
			# we missed the URN command, so put it in
			array_unshift($args, $Message);
			$this->messageHandler["urn"]($this, $nick, $account, $to, $args);
		}
		if ($to[0] == "#") {
			# keep track of the user's public messages
			if ($this->chatlog[$nick] == null) {
				$this->chatlog[$nick] = array();
			}
			array_push($this->chatlog[$nick], trim(substr($capturedMessage, 1)));
		}
	}
	function Send($to, $msg) {
		$this->Emit("PRIVMSG $to :$msg\r\n");
	}
	function DelaySend($to, $msg) {
		$array = explode(" ", $msg);
		$msg = null;
		foreach ($array as $m) {
			if ($msg == null) {
				$msg = $m;
			} else {
				$msg = "$msg $m";
			}
			if (strlen($msg) > 440) {
				$this->Emit("PRIVMSG $to :$oldmsg\r\n");
				sleep(3);
				$oldmsg = "";
				$msg = $m;
			} else {
				$oldmsg = $msg;
			}
		}
		$this->Emit("PRIVMSG $to :$oldmsg\r\n");
	}
	function Emit($data) {
		fwrite($this->log, $data);
		fwrite($this->fp, $data);
		fflush($this->fp);
	}
	function setup() {


		$this->on("PRIVMSG", function($bot, $array, $cmd) {
			$bot->Emit($this->ProcessMessage($array));
		});

		$this->OnPrivMsg("silence", function($bot, $from, $account, $to, $args) {
			$bot->quiet = true;
		});
		$this->OnPrivMsg("invite", function($bot, $from, $account, $to, $args) {
			$bot->Rejoin();
		});
		$this->OnPrivMsg("urn", function($bot, $from, $account, $to, $args) {
			if ($to[0] == "#") {
				# $bot->Send($from, "Public requests aren't allowed!");
			} else {
				$bot->UrnQuote($from, $args);
			}
		});

		$this->OnPrivMsg("remember", function($bot, $from, $account, $to, $args) {
			if (!$bot->users[$account]) {
				$bot->Send($from, "Access denied!");
				return;
			}
			if ($to[0] == "#") {
				# $bot->Send($from, "Public requests aren't allowed!");
			} else {
				$bot->Insert($from, $args);
			}
		});

		$this->OnPrivMsg("set", function($bot, $from, $account, $to, $args) {
			if ($bot->users[$account] && $args[0] && $bot->dbs[$args[0]]) {
				$bot->dbname = $args[0];
				$bot->reloadDB();
				$bot->Send($from, "Reloaded!");
			} else {
				$bot->Send($from, "Access denied!");
			}
		});

		$this->OnPrivMsg("capture", function($bot, $from, $account, $to, $args) {
			if (!$bot->users[$account]) {
				$bot->Send($from, "Access denied!");
				return;
			}
			if ($to[0] == "#") {
				# $bot->Send($from, "Public requests aren't allowed!");
			} else {
				$bot->Capture($from, $args);
			}
		});

		$this->OnPrivMsg("quote", function($bot, $from, $account, $to, $args) {
			if ($to[0] == "#") {
				# $bot->Send($from, "Public requests aren't allowed!");
			} else {
				$bot->RandomQuote($args[0]);
			}
		});

		$this->OnPrivMsg("help", function($bot, $from, $account, $to, $args) {
			if ($to[0] == "#") {
				if ($bot->channel) {
					$bot->Send($bot->channel, "To get help, type /msg $bot->botnick help");
				}
			} else {
				$bot->Send($from, "Help for $bot->botnick.  Replace the item in all caps with an identifier or almost anything.");
				$bot->Send($from, "/msg $bot->botnick help -- this message");
				$bot->Send($from, "/msg $bot->botnick quote TAG -- random quote with optional tag");
				$bot->Send($from, "/msg $bot->botnick remember URN|NICK_NAME|TAG|QUOTE -- put a quote in the database");
				$bot->Send($from, "/msg $bot->botnick capture URN|NICK_NAME|TAG|NUMBER_OF_LINES -- capture a quote into the database from the last few lines of someone's nickname");
				$bot->Send($from, "/msg $bot->botnick urn URN -- recall a quote by urn the command is optional except when another command (example 'quote') is used as a URN");
				$bot->Send($from, "/msg $bot->botnick set DATABASE_NAME -- set the name of the database.  Current options are {quote}");
				$bot->Send($from, "/msg $bot->botnick silence -- silence the bot.  Undone with quote or urn commands.");
				$bot->Send($from, "/msg $bot->botnick invite -- invite the bot to join the channel.");
				$bot->Send($from, "If you want something to be uncaptureable, use | in the line.");
			}
		});
	}
};


$bots = array();

$bot = new IRCBot("qb2");
array_push($bots, $bot->Connect("afternet.txt", "tcp://irc.afternet.org:6667", "#PhoenixRising"));

#$bot = new IRCBot("qb");
#array_push($bots, $bot->Connect("ircstorm.txt", "tcp://irc.ircstorm.net:6667", "#PhoenixRising"));



do {
	$connected = false;
	foreach ($bots as $bot) {
		if ($bot) {
			$con = $bot->checkForMessages();
			if ($con) {
				# one is still connected
				$connected = true;
			} else {
				$bot->Disconnect();
			}
		} else {
			echo $bot->error;
		}
	}
		
} while ($connected);
?>
