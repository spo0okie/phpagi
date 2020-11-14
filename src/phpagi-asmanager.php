<?php
/**
 * phpagi-asmanager.php : PHP Asterisk Manager functions
 * @see https://github.com/welltime/phpagi
 * @filesource http://phpagi.sourceforge.net/
 *
 * $Id: phpagi-asmanager.php,v 1.10 2005/05/25 18:43:48 pinhole Exp $
 *
 * Copyright (c) 2004 - 2010 Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
 * All Rights Reserved.
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 *  A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * We would be happy to list your phpagi based application on the phpagi
 * website.  Drop me an Email if you'd like us to list your program.
 *
 * @package phpAGI
 * @version 2.0
 */

if (!defined('MSG_DONTWAIT')) define('MSG_DONTWAIT', 0x40);

/**
 * Written for PHP 4.3.4, should work with older PHP 4.x versions.
 * Please submit bug reports, patches, etc to https://github.com/welltime/phpagi
 *
 */

if (!class_exists('AGI')) {
	require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phpagi.php');
}

if (!class_exists('CAsyncSocket')) {
	require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'CAsyncSocket.php');
}


/**
 * Asterisk Manager class
 *
 * @link http://www.voip-info.org/wiki-Asterisk+config+manager.conf
 * @link http://www.voip-info.org/wiki-Asterisk+manager+API
 * @example examples/sip_show_peer.php Get information about a sip peer
 * @package phpAGI
 */
class AGI_AsteriskManager
{
	/**
	 * Config variables
	 *
	 * @var array
	 * @access public
	 */
	public $config;

	/**
	 * Socket
	 *
	 * @access public
	 * @var CAsyncSocket
	 */
	public $socket = NULL;



	/**
	 * Server we are connected to
	 *
	 * @access public
	 * @var string
	 */
	public $server;

	/**
	 * Port on the server we are connected to
	 *
	 * @access public
	 * @var integer
	 */
	public $port;

	/**
	 * Parent AGI
	 *
	 * @access private
	 * @var AGI
	 */
	public $pagi = false;

	/**
	 * Event Handlers
	 *
	 * @access private
	 * @var array
	 */
	private $event_handlers;

	/**
	 * log function
	 * @var callable
	 */
	public $log_handler = NULL;

	private $_buffer = NULL;

	/**
	 * Whether we're successfully logged in
	 *
	 * @access private
	 * @var boolean
	 */
	private $_logged_in = FALSE;

	/**
	 * куча ответов. Поскольку канал общения один, и ответ на запрос приходит не сразу, то ожидая
	 * свой ответ, можно получить чужой, тогда его надо положить в кучу
	 * при ожидании ответа надо не только ждать его прям с текущего потока событий, а и из кучи
	 * @var array
	 */
	private $responses = array();

	/**
	 * очередь ивентов. поскольку обработчик какого либо ивента может создать остановку текущего
	 * ожидания ответа. то в случае ожидания ответа ивенты не будем обрабатывать, а сложим в очередь.
	 * обрабатывать ивенты будут только в случае ожидания чего угодно.
	 * @var array
	 */
	private $events_queue = array();

	/**
	 * Количество обнаруженных событий
	 * нужно для того, чтобы вместе с событием передавать его номер,
	 * т.к. события переданные по http, и обрабатываемые системой получателем могут приниматься не в
	 * том порядке, в котором были отправлены (jitter)
	 * @var integer
	 */
	private $events_count=0;

	/**
	 * @param $agi
	 */
	public function setPagi(&$agi)
	{
		$this->pagi = $agi;
	}

	/**
	 * Returns current count of events and responses in heaps
	 *
	 * @return string current status
	 */
	private function p()
	{
		return 'asManager(evt:' . count($this->events_queue) . ',rsp:' . count($this->responses).'): ';
	}

	/**
	 * Returns current count of events and responses in heaps
	 *
	 * @return string current status
	 */
	public function dump()
	{
		return 'asManager (evt:' . count($this->events_queue) . ',rsp:' . count($this->responses).') ';
	}

	/**
	 * Constructor
	 *
	 * @param string $config is the name of the config file to parse or a parent agi from which to read the config
	 * @param array $optconfig is an array of configuration vars and vals, stuffed into $this->config['asmanager']
	 */
	function __construct($config = NULL, $optconfig = array())
	{
		// load config
		if (!is_null($config) && file_exists($config))
			$this->config = parse_ini_file($config, true);
		elseif (file_exists(DEFAULT_PHPAGI_CONFIG))
			$this->config = parse_ini_file(DEFAULT_PHPAGI_CONFIG, true);

		// If optconfig is specified, stuff vals and vars into 'asmanager' config array.
		foreach ($optconfig as $var => $val)
			$this->config['asmanager'][$var] = $val;

		// add default values to config for uninitialized values
		if (!isset($this->config['asmanager']['server'])) $this->config['asmanager']['server'] = 'localhost';
		if (!isset($this->config['asmanager']['port'])) $this->config['asmanager']['port'] = 5038;
		if (!isset($this->config['asmanager']['username'])) $this->config['asmanager']['username'] = 'phpagi';
		if (!isset($this->config['asmanager']['secret'])) $this->config['asmanager']['secret'] = 'phpagi';
		if (!isset($this->config['asmanager']['write_log'])) $this->config['asmanager']['write_log'] = false;
	}

	/**
	 * Send a request
	 *
	 * @param string $action
	 * @param array $parameters
	 * @return array of parameters
	 */
	function send_request($action, $parameters = array())
	{
		$req = "Action: $action\r\n";
		$actionid = null;
		foreach ($parameters as $var => $val) {
			if (is_array($val)) {
				foreach ($val as $line) {
					$req .= "$var: $line\r\n";
				}
			} else {
				$req .= "$var: $val\r\n";
				if (strtolower($var) == "actionid") {
					$actionid = $val;
				}
			}
		}
		if (!$actionid) {
			$actionid = $this->ActionID();
			$req .= "ActionID: $actionid\r\n";
		}
		$req .= "\r\n";

		$this->log("Request:\n" . $req, REQUESTS_LOG_LEVEL);
		$this->socket->write($req);

		return $this->wait_response(false, $actionid);
	}




	function read_one_msg($allow_timeout = false)
	{
		$type = null;

		//do {
			$buf = $this->socket->read_all();
			$this->_buffer .= $buf;

			$pos = strpos($this->_buffer, "\r\n\r\n");
			if (false === $pos) {
				// there's a full message in the buffer
				return null;
			}
		//} while (!$this->socket->error());

		$msg = substr($this->_buffer, 0, $pos);
		$this->_buffer = substr($this->_buffer, $pos + 4);

		$msgarr = explode("\r\n", $msg);

		$parameters = array();

		$r = explode(': ', $msgarr[0]);
		$type = strtolower($r[0]);

		if ($r[1] == 'Follows') {
			$str = array_pop($msgarr);
			$lastline = strpos($str, '--END COMMAND--');
			if (false !== $lastline) {
				$parameters['data'] = substr($str, 0, $lastline - 1); // cut '\n' too
			}
		}

		foreach ($msgarr as $num => $str) {
			$kv = explode(':', $str, 2);
			if (!isset($kv[1])) {
				$kv[1] = "";
			}
			$key = trim($kv[0]);
			$val = trim($kv[1]);
			$parameters[$key] = $val;
		}

		// process response
		switch ($type) {
			case 'event':
				$parameters['uid']=$this->events_count++;
				if ($this->events_count>=PHP_INT_MAX) $this->events_count=0;
				array_push($this->events_queue, $parameters);
				$this->log("Event queued: " . print_r($parameters, true), EVENTS_LOG_LEVEL);
				break;
			case 'response':
				if (isset($parameters['ActionID']))
					$this->responses[$parameters['ActionID']] = $parameters;
				else {
					$this->log('WARNING: Got response with an empty Action ID! : ' . print_r($parameters, true));
					//наверно такие надо класть в отдельную кучу
					$this->responses[] = $parameters;
				}
				$this->log("Response: " . print_r($parameters, true), RESPONSES_LOG_LEVEL);
				break;
			default:
				$this->log('Unhandled response packet from Manager: ' . print_r($parameters, true));
				break;
		}

		return NULL;
	}

	/**
	 * Вытаскивает евент из кучи по принципу fifo и удаляет его там
	 * @return array parameters
	 */
	function fetch_event()
	{
		if (!count($this->events_queue)) return NULL;
		$parameters = array_shift($this->events_queue);
		$this->log($parameters['Event'] . ' fetched from queue', IGNORED_EVENTS_LOG_LEVEL);
		return $parameters;
	}

	/**
	 * Wait for a response
	 *
	 * If a request was just sent, this will return the response.
	 * Otherwise, it will loop forever, handling events.
	 *
	 * XXX this code is slightly better then the original one
	 * however it's still totally screwed up and needs to be rewritten,
	 * for two reasons at least:
	 * 1. it does not handle socket errors in any way
	 * 2. it is terribly synchronous, esp. with eventlists,
	 *    i.e. your code is blocked on waiting until full responce is received
	 *
	 * @param boolean $allow_timeout if the socket times out, return an empty array
	 * @return array of parameters, empty on timeout
	 */
	function wait_response($allow_timeout = false, $actionid = null)
	{
		$res = array();
		if ($actionid) {
			do {//проверяем кучу
				//если есть такой ответ
				if (isset($this->responses[$actionid])) {
					$parameters = $this->responses[$actionid];
					unset ($this->responses[$actionid]);
					/*
					if (isset($res['EventList']) && $res['EventList']=='start') {
					  $evlist = array();
					  do {
						$res = $this->wait_response(false, $actionid);
						if (isset($res['EventList']) && $res['EventList']=='Complete')
						  break;
						else
						  $evlist[] = $res;
					  } while(true);
					  $res['events'] = $evlist;
					}

					return $res;
					*/
					return $parameters;
				} else $this->read_one_msg();
			} while (TRUE);
		} else {//иначе обрабатываем ивенты. поискал, вроде это вообще нигде не вызывается
			do {
				$this->process_event($this->fetch_event());
				$this->read_one_msg();
			} while (count($this->events_queue));
		}


	}


	/**
	 * Connect to Asterisk
	 *
	 * @example examples/sip_show_peer.php Get information about a sip peer
	 *
	 * @param string $server
	 * @param string $username
	 * @param string $secret
	 * @return boolean true on success
	 */
	function connect($server = NULL, $username = NULL, $secret = NULL)
	{
		// use config if not specified
		if (is_null($server)) $server = $this->config['asmanager']['server'];
		if (is_null($username)) $username = $this->config['asmanager']['username'];
		if (is_null($secret)) $secret = $this->config['asmanager']['secret'];

		// get port from server if specified
		if (strpos($server, ':') !== false) {
			$c = explode(':', $server);
			$this->server = $c[0];
			$this->port = $c[1];
		} else {
			$this->server = $server;
			$this->port = $this->config['asmanager']['port'];
		}

		// connect the socket
		$this->socket = new CAsyncSocket($this->server, $this->port);

		// read the header
		$str = $this->socket->read_all(1000);
		if ($str === false) {
			// a problem.
			$this->log("Asterisk Manager header not received.");
			return false;
		}

		// login
		$res = $this->send_request('login', array('Username' => $username, 'Secret' => $secret));
		if ($res['Response'] != 'Success') {
			$this->_logged_in = FALSE;
			$this->log("Failed to login.");
			$this->disconnect();
			return false;
		}
		$this->_logged_in = TRUE;
		return true;
	}

	/**
	 * Disconnect
	 *
	 * @example examples/sip_show_peer.php Get information about a sip peer
	 */
	function disconnect()
	{
		if ($this->_logged_in == TRUE)
			$this->logoff();
		//fclose($this->socket);
		$this->socket->close();
		unset ($this->socket);
	}

	// *********************************************************************************************************
	// **                       COMMANDS                                                                      **
	// *********************************************************************************************************

	/**
	 * Set Absolute Timeout
	 *
	 * Hangup a channel after a certain time.
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+AbsoluteTimeout
	 * @param string $channel Channel name to hangup
	 * @param integer $timeout Maximum duration of the call (sec)
	 * @return array
	 */
	function AbsoluteTimeout($channel, $timeout)
	{
		return $this->send_request('AbsoluteTimeout', array('Channel' => $channel, 'Timeout' => $timeout));
	}

	/**
	 * Change monitoring filename of a channel
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ChangeMonitor
	 * @param string $channel the channel to record.
	 * @param string $file the new name of the file created in the monitor spool directory.
	 * @return array
	 */
	function ChangeMonitor($channel, $file)
	{
		return $this->send_request('ChangeMontior', array('Channel' => $channel, 'File' => $file));
	}

	/**
	 * Execute Command
	 *
	 * @example examples/sip_show_peer.php Get information about a sip peer
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Command
	 * @link http://www.voip-info.org/wiki-Asterisk+CLI
	 * @param string $command
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function Command($command, $actionid = NULL)
	{
		$parameters = array('Command' => $command);
		if ($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('Command', $parameters);
	}

	/**
	 * Enable/Disable sending of events to this manager
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Events
	 * @param string $eventmask is either 'on', 'off', or 'system,call,log'
	 * @return array
	 */
	function Events($eventmask)
	{
		return $this->send_request('Events', array('EventMask' => $eventmask));
	}

	/**
	 *  Generate random ActionID
	 **/
	function ActionID()
	{
		return "A" . sprintf(rand(), "%6d");
	}

	/**
	 * DBGet
	 * http://www.voip-info.org/wiki/index.php?page=Asterisk+Manager+API+Action+DBGet
	 * @param string $family key family
	 * @param string $key key name
	 *
	 * @return mixed|string
	 */
	function DBGet($family, $key, $actionid = NULL)
	{
		$parameters = array('Family' => $family, 'Key' => $key);
		if ($actionid == NULL)
			$actionid = $this->ActionID();
		$parameters['ActionID'] = $actionid;
		$response = $this->send_request("DBGet", $parameters);
		if ($response['Response'] == "Success") {
			$response = $this->wait_response(false, $actionid);
			return $response['Val'];
		}
		return "";
	}

	/**
	 * Check Extension Status
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ExtensionState
	 * @param string $exten Extension to check state on
	 * @param string $context Context for extension
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function ExtensionState($exten, $context, $actionid = NULL)
	{
		$parameters = array('Exten' => $exten, 'Context' => $context);
		if ($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('ExtensionState', $parameters);
	}

	/**
	 * Gets a Channel Variable
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+GetVar
	 * @link http://www.voip-info.org/wiki-Asterisk+variables
	 * @param string $channel Channel to read variable from
	 * @param string $variable
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function GetVar($channel, $variable, $actionid = NULL)
	{
		$parameters = array('Channel' => $channel, 'Variable' => $variable);
		if ($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('GetVar', $parameters);
	}

	/**
	 * Hangup Channel
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Hangup
	 * @param string $channel The channel name to be hungup
	 * @return array
	 */
	function Hangup($channel)
	{
		return $this->send_request('Hangup', array('Channel' => $channel));
	}

	/**
	 * List IAX Peers
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+IAXpeers
	 */
	function IAXPeers()
	{
		return $this->send_request('IAXPeers');
	}

	/**
	 * List available manager commands
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ListCommands
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function ListCommands($actionid = NULL)
	{
		if ($actionid)
			return $this->send_request('ListCommands', array('ActionID' => $actionid));
		else
			return $this->send_request('ListCommands');
	}

	/**
	 * Logoff Manager
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Logoff
	 */
	function Logoff()
	{
		return $this->send_request('Logoff');
	}

	/**
	 * Check Mailbox Message Count
	 *
	 * Returns number of new and old messages.
	 *   Message: Mailbox Message Count
	 *   Mailbox: <mailboxid>
	 *   NewMessages: <count>
	 *   OldMessages: <count>
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxCount
	 * @param string $mailbox Full mailbox ID <mailbox>@<vm-context>
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function MailboxCount($mailbox, $actionid = NULL)
	{
		$parameters = array('Mailbox' => $mailbox);
		if ($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('MailboxCount', $parameters);
	}

	/**
	 * Check Mailbox
	 *
	 * Returns number of messages.
	 *   Message: Mailbox Status
	 *   Mailbox: <mailboxid>
	 *   Waiting: <count>
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxStatus
	 * @param string $mailbox Full mailbox ID <mailbox>@<vm-context>
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function MailboxStatus($mailbox, $actionid = NULL)
	{
		$parameters = array('Mailbox' => $mailbox);
		if ($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('MailboxStatus', $parameters);
	}

	/**
	 * Monitor a channel
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Monitor
	 * @param string $channel
	 * @param string $file
	 * @param string $format
	 * @param boolean $mix
	 * @return array
	 */
	function Monitor($channel, $file = NULL, $format = NULL, $mix = NULL)
	{
		$parameters = array('Channel' => $channel);
		if ($file) $parameters['File'] = $file;
		if ($format) $parameters['Format'] = $format;
		if (!is_null($file)) $parameters['Mix'] = ($mix) ? 'true' : 'false';
		return $this->send_request('Monitor', $parameters);
	}

	/**
	 * Originate Call
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Originate
	 * @param string $channel Channel name to call
	 * @param string $exten Extension to use (requires 'Context' and 'Priority')
	 * @param string $context Context to use (requires 'Exten' and 'Priority')
	 * @param string $priority Priority to use (requires 'Exten' and 'Context')
	 * @param string $application Application to use
	 * @param string $data Data to use (requires 'Application')
	 * @param integer $timeout How long to wait for call to be answered (in ms)
	 * @param string $callerid Caller ID to be set on the outgoing channel
	 * @param string $variable Channel variable to set (VAR1=value1|VAR2=value2)
	 * @param string $account Account code
	 * @param boolean $async true fast origination
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function Originate($channel,
					   $exten = NULL, $context = NULL, $priority = NULL,
					   $application = NULL, $data = NULL,
					   $timeout = NULL, $callerid = NULL, $variable = NULL, $account = NULL, $async = NULL, $actionid = NULL)
	{
		$parameters = array('Channel' => $channel);

		if ($exten) $parameters['Exten'] = $exten;
		if ($context) $parameters['Context'] = $context;
		if ($priority) $parameters['Priority'] = $priority;

		if ($application) $parameters['Application'] = $application;
		if ($data) $parameters['Data'] = $data;

		if ($timeout) $parameters['Timeout'] = $timeout;
		if ($callerid) $parameters['CallerID'] = $callerid;
		if ($variable) $parameters['Variable'] = $variable;
		if ($account) $parameters['Account'] = $account;
		if (!is_null($async)) $parameters['Async'] = ($async) ? 'true' : 'false';
		if ($actionid) $parameters['ActionID'] = $actionid;

		return $this->send_request('Originate', $parameters);
	}

	/**
	 * List parked calls
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ParkedCalls
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function ParkedCalls($actionid = NULL)
	{
		if ($actionid)
			return $this->send_request('ParkedCalls', array('ActionID' => $actionid));
		else
			return $this->send_request('ParkedCalls');
	}

	/**
	 * Ping
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Ping
	 */
	function Ping()
	{
		return $this->send_request('Ping');
	}

	/**
	 * Queue Add
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueAdd
	 * @param string $queue
	 * @param string $interface
	 * @param integer $penalty
	 * @param string|boolean $memberName
	 * @return array
	 */
	function QueueAdd($queue, $interface, $penalty = 0, $memberName = false)
	{
		$parameters = array('Queue' => $queue, 'Interface' => $interface);
		if ($penalty) $parameters['Penalty'] = $penalty;
		if ($memberName) $parameters["MemberName"] = $memberName;
		return $this->send_request('QueueAdd', $parameters);
	}

	/**
	 * Queue Remove
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueRemove
	 * @param string $queue
	 * @param string $interface
	 * @return array
	 */
	function QueueRemove($queue, $interface)
	{
		return $this->send_request('QueueRemove', array('Queue' => $queue, 'Interface' => $interface));
	}

	function QueueReload()
	{
		return $this->send_request('QueueReload');
	}

	/**
	 * Queues
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Queues
	 */
	function Queues()
	{
		return $this->send_request('Queues');
	}

	/**
	 * Queue Status
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueStatus
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function QueueStatus($actionid = NULL)
	{
		if ($actionid)
			return $this->send_request('QueueStatus', array('ActionID' => $actionid));
		else
			return $this->send_request('QueueStatus');
	}

	/**
	 * Redirect
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Redirect
	 * @param string $channel
	 * @param string $extrachannel
	 * @param string $exten
	 * @param string $context
	 * @param string $priority
	 * @return array
	 */
	function Redirect($channel, $extrachannel, $exten, $context, $priority)
	{
		return $this->send_request('Redirect', array('Channel' => $channel, 'ExtraChannel' => $extrachannel, 'Exten' => $exten,
			'Context' => $context, 'Priority' => $priority));
	}

	/**
	 * Set the CDR UserField
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetCDRUserField
	 * @param string $userfield
	 * @param string $channel
	 * @param string $append
	 * @return array
	 */
	function SetCDRUserField($userfield, $channel, $append = NULL)
	{
		$parameters = array('UserField' => $userfield, 'Channel' => $channel);
		if ($append) $parameters['Append'] = $append;
		return $this->send_request('SetCDRUserField', $parameters);
	}

	/**
	 * Set Channel Variable
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetVar
	 * @param string $channel Channel to set variable for
	 * @param string $variable name
	 * @param string $value
	 * @return array
	 */
	function SetVar($channel, $variable, $value)
	{
		return $this->send_request('SetVar', array('Channel' => $channel, 'Variable' => $variable, 'Value' => $value));
	}

	/**
	 * Channel Status
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Status
	 * @param string $channel
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function Status($channel, $actionid = NULL)
	{
		$parameters = array('Channel' => $channel);
		if ($actionid) $parameters['ActionID'] = $actionid;
		return $this->send_request('Status', $parameters);
	}

	/**
	 * Stop monitoring a channel
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+StopMonitor
	 * @param string $channel
	 * @return array
	 */
	function StopMonitor($channel)
	{
		return $this->send_request('StopMonitor', array('Channel' => $channel));
	}

	/**
	 * Dial over Zap channel while offhook
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDialOffhook
	 * @param string $zapchannel
	 * @param string $number
	 * @return array
	 */
	function ZapDialOffhook($zapchannel, $number)
	{
		return $this->send_request('ZapDialOffhook', array('ZapChannel' => $zapchannel, 'Number' => $number));
	}

	/**
	 * Toggle Zap channel Do Not Disturb status OFF
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDoff
	 * @param string $zapchannel
	 * @return array
	 */
	function ZapDNDoff($zapchannel)
	{
		return $this->send_request('ZapDNDoff', array('ZapChannel' => $zapchannel));
	}

	/**
	 * Toggle Zap channel Do Not Disturb status ON
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDon
	 * @param string $zapchannel
	 * @return array
	 */
	function ZapDNDon($zapchannel)
	{
		return $this->send_request('ZapDNDon', array('ZapChannel' => $zapchannel));
	}

	/**
	 * Hangup Zap Channel
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapHangup
	 * @param string $zapchannel
	 * @return array
	 */
	function ZapHangup($zapchannel)
	{
		return $this->send_request('ZapHangup', array('ZapChannel' => $zapchannel));
	}

	/**
	 * Transfer Zap Channel
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapTransfer
	 * @param string $zapchannel
	 * @return array
	 */
	function ZapTransfer($zapchannel)
	{
		return $this->send_request('ZapTransfer', array('ZapChannel' => $zapchannel));
	}

	/**
	 * Zap Show Channels
	 *
	 * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapShowChannels
	 * @param string $actionid message matching variable
	 * @return array
	 */
	function ZapShowChannels($actionid = NULL)
	{
		if ($actionid)
			return $this->send_request('ZapShowChannels', array('ActionID' => $actionid));
		else
			return $this->send_request('ZapShowChannels');
	}

	// *********************************************************************************************************
	// **                       MISC                                                                          **
	// *********************************************************************************************************

	/*
	 * Log a message
	 *
	 * @param string $message
	 * @param integer $level from 1 to 4
	 */
	function log($message, $level = 1)
	{
		if ($this->pagi != false)
			$this->pagi->conlog($message, $level);
		elseif (!empty($this->log_handler))
			call_user_func($this->log_handler, $this->p().$message, $level);
		elseif ($this->config['asmanager']['write_log'])
			error_log(date('r') . ' - ' . $message);
	}

	/**
	 * Add event handler
	 *
	 * Known Events include ( http://www.voip-info.org/wiki-asterisk+manager+events )
	 *   Link - Fired when two voice channels are linked together and voice data exchange commences.
	 *   Unlink - Fired when a link between two voice channels is discontinued, for example, just before call completion.
	 *   Newexten -
	 *   Hangup -
	 *   Newchannel -
	 *   Newstate -
	 *   Reload - Fired when the "RELOAD" console command is executed.
	 *   Shutdown -
	 *   ExtensionStatus -
	 *   Rename -
	 *   Newcallerid -
	 *   Alarm -
	 *   AlarmClear -
	 *   Agentcallbacklogoff -
	 *   Agentcallbacklogin -
	 *   Agentlogoff -
	 *   MeetmeJoin -
	 *   MessageWaiting -
	 *   join -
	 *   leave -
	 *   AgentCalled -
	 *   ParkedCall - Fired after ParkedCalls
	 *   Cdr -
	 *   ParkedCallsComplete -
	 *   QueueParams -
	 *   QueueMember -
	 *   QueueStatusEnd -
	 *   Status -
	 *   StatusComplete -
	 *   ZapShowChannels - Fired after ZapShowChannels
	 *   ZapShowChannelsComplete -
	 *
	 * @param string $event type or * for default handler
	 * @param string $callback function
	 * @return boolean sucess
	 */
	function add_event_handler($event, $callback)
	{
		$event = strtolower($event);
		if (isset($this->event_handlers[$event])) {
			$this->log("$event handler is already defined, not over-writing.");
			return false;
		}
		$this->event_handlers[$event] = $callback;
		return true;
	}

	/**
	 *
	 *   Remove event handler
	 *
	 * @param string $event type or * for default handler
	 * @return boolean sucess
	 **/
	function remove_event_handler($event)
	{
		$event = strtolower($event);
		if (isset($this->event_handlers[$event])) {
			unset($this->event_handlers[$event]);
			return true;
		}
		$this->log("$event handler is not defined.");
		return false;
	}

	/**
	 * Process event
	 *
	 * @access private
	 * @param array $parameters
	 * @return mixed result of event handler or false if no handler was found
	 */
	function process_event($parameters)
	{
		$ret = false;
		$e = is_array($parameters)?strtolower($parameters['Event']):'null';
		$this->log("Got event.. $e", IGNORED_EVENTS_LOG_LEVEL);

		$handler = '';
		if (isset($this->event_handlers[$e])) $handler = $this->event_handlers[$e];
		elseif (isset($this->event_handlers['*'])) $handler = $this->event_handlers['*'];

		if (is_array($handler) && method_exists($handler[0], $handler[1])) { //сначала проверяем вариант массива, иначе проверка function_exists ругается
			$this->log("processing $e => " . get_class($handler[0]) . "::$handler[1]", IGNORED_EVENTS_LOG_LEVEL);
			$ret = call_user_func($handler, $e, $parameters, $this->server, $this->port);
		} elseif (function_exists($handler)) {
			$this->log("processing $e => $handler", IGNORED_EVENTS_LOG_LEVEL);
			$ret = $handler($e, $parameters, $this->server, $this->port);
		} else
			$this->log("No event handler for event '$e'");
		return $ret;
	}
}

?>
