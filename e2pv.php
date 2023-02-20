<?php
/*
 * Copyright (c) 2015 Otto Moerbeek <otto@drijf.net>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */
/* Updates by Stuart Ashby <slash909@gmail.com>:
 * 1/ Syslog event logging inspired by; https://gist.github.com/coderofsalvation/11325307
 * 2/ Remote alarm mod ; emits warning when panels stop generating or in error state:
 * alarm format is:
 * alarm message := code.msg
 *   code := 100|101
 *   if(code=100) msg := status.panel
 *   if(code==101) msg := activecount.panels
 *   status := [0-9]+
 *   activecount := [0-9]+
 *   panels := panel[.panels]
 *   panel := [0-9]+
 *
 * 3/ VERBOSE param to turn on/off per inverter report to stdout
 * 4/ mapping inverter ID to a panel number for easier identification on system
 * refer config.php for panels array
 * 5/ Message forwarding to chained server e.g. enecsysparts.com IGS. Defaults to 'off', toggle on/off with SIGHUP (=>upstart reload command)
 * 6/ Local generating updates pushed to domoticz via MQTT to avoid PVOutput.org delay
 * 7/ Filter top bit of State as it seems to always be set?
 * 8/ always report timestamps in UTC
 * 9/ VERBOSE report uses map_panel to ease identification
 * 10/ add voltage reporting to a simple MQTT topic to support openevse power calculation
 * 11/ add runtime debug and verbose toggles via MQTT cmds
 */

echo "E2PV starting up\n";

// By default, $ignored array is empty
$ignored = array();

// By default, $panels array is empty
$panels = array();

// See README.md for details on config.php - note we do not use require_once as we need to re-read incase of SIGHUP
require 'config.php';

// In case LIFETIME is not defined in config.php, default to LIFETIME mode
if (!defined('LIFETIME'))
  define('LIFETIME', 1);
// In case EXTENDED is not defined in config.php, do not send state counts
if (!defined('EXTENDED'))
  define('EXTENDED', 0);
// In case AC is not defined in config.php, default to 0
if (!defined('AC'))
  define('AC', 0);
// In case ALARM_CMD is not defined in config.php, default to empty string
if (!defined('ALARM_CMD'))
  define('ALARM_CMD', '');
// In case ALARM_ON_DROP is not defined in config.php, default to 0
if (!defined('ALARM_ON_DROP'))
  define('ALARM_ON_DROP', 0);
// In case VERBOSE is not defined in config.php, default to 1
if (!defined('VERBOSE'))
  define('VERBOSE', 1);
// In case FACILITY is not defined in config.php, default to LOG_DAEMON
if (!defined('FACILITY'))
  define('FACILITY', LOG_DAEMON);
// In case LOGHOST is not defined in config.php, default to 127.0.0.1
if (!defined('LOGHOST'))
  define('LOGHOST', '127.0.0.1');
// In case FWDHOSTPORT is not defined in config.php, default to empty string
if (!strlen($FWDHOSTPORT))
  $FWDHOSTPORT = '';
// In case MQTT_INTERVAL is not defined in config.php, default to 10sec
if (!defined('MQTT_INTERVAL'))
  define('MQTT_INTERVAL', 10);

// debug output toggle
define('DEBUG', 0);
// make bool vars, not defines so we can manipulate at runtime
$debug = DEBUG===0 ? false:true;
$verbose = VERBOSE===0 ? false:true;
/*
 * Report a message
 */
// include Syslog class for remote syslog feature
require_once "../Syslog-master/Syslog.php";
Syslog::$hostname = LOGHOST;
Syslog::$facility = FACILITY;
Syslog::$hostToLog = "e2pv";

// include MQTT class for local reporting to domoticz
require_once "../phpMQTT-master/phpMQTT.php";
$server = "localhost";     // change if necessary
$port = 1883;                     // change if necessary
$client_id = "e2pv-subscriber"; // make sure this is unique for connecting to sever - you could use uniqid()
// make MQTT instance
$mqtt = new phpMQTT($server, $port, $client_id);


function report($msg, $level = LOG_INFO, $cmp = "e2pv-svr") {
	global $debug;
	if ($level === LOG_DEBUG && !$debug) return; // skip debug messages if not enabled
	Syslog::send($msg, $level, $cmp);
	if($debug) print $msg.PHP_EOL;
}

/*
 * Fatal error, likely a configuration issue
 */
function fatal($msg) {
  report($msg . ': ' . socket_strerror(socket_last_error()), LOG_ALERT);
  exit(1);
}

/*
 * Send alarm for an inverter
 */
function alarm($message) {
  report('ALARM!: ' . $message, LOG_WARNING);
  if (strlen(ALARM_CMD)) {
    // invoke external cmd and append inverter id
    exec(ALARM_CMD . ' ' . $message);
    report('sent alarm to: '.ALARM_CMD, LOG_DEBUG);
  }
}
/*
 * Forward Enecsys message
 */
$fwdsock = false;
$fwdhostporttemp = '';
function fwd_msg($msg) {
	global $fwdsock;
	global $fwdhostporttemp; // see SIGHUP handler below
	// only process if FWDHOSTPORT not empty
	if(strlen($fwdhostporttemp)) {
		// open socket if closed
		if($fwdsock === false) {
			report('Open forwarding connection to:'.$fwdhostporttemp,LOG_INFO);
			$fwdsock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			socket_set_option($fwdsock, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 1, 'usec' => 0));  // set a short timeout for connecting incase server dies; else this will delay processing messages
			list($fwdhost, $fwdport) = explode(':',$fwdhostporttemp);
			$result = socket_connect($fwdsock, $fwdhost, $fwdport);
			if ($result === false) {
				report('Cannot connect to forwarding server: '.$result.': '.socket_strerror(socket_last_error($fwdsock)),LOG_WARNING);
				return; // avoid falling into send/rcv code if socket is dead
			}
		}
		// fwd message
		report('fwdsock send: '.$msg,LOG_DEBUG);
		if(false === socket_write($fwdsock,$msg,strlen($msg))) {
			report('Cannot write to forwarding server: '.$result.': '.socket_strerror(socket_last_error($fwdsock)),LOG_WARNING);
			socket_close($fwdsock);
			$fwdsock = false;
			return; // avoid falling into recv with dead socket!
		};
		// dump any responses - we dont care!
		$junk = '';
		while(false !== socket_recv($fwdsock, $junk, 2048, MSG_DONTWAIT)) {
			report('fwdsock got: '.$junk,LOG_DEBUG);
		};
	}
}

/*
 * SIGHUP handler to turn on/off FWDHOSTPORT without restart
 */
//pcntl_async_signals(TRUE); //not supported in 5.6
declare(ticks = 1); //ouch ...

pcntl_signal(SIGHUP, function($signal) {
// toggle FWDHOSTPORT value and reset socket
	global $fwdsock;
	global $fwdhostporttemp;
	global $FWDHOSTPORT;
	if(strlen($fwdhostporttemp)) { $fwdhostporttemp = ''; $state='off'; }
	else { include 'config.php'; $fwdhostporttemp = $FWDHOSTPORT; $state='on';} // re-read config here incase $FWDHOSTPORT has changed
	report('Forwarding '.$state,LOG_INFO);
	if($fwdsock !== false) { socket_close($fwdsock); $fwdsock = false; }
});
 
/*
 * map panel ID
 */
function map_panel($inverter_id) {
  global $panels;
  $id = $inverter_id;
  if ($panels) {
    $id = $panels[$inverter_id];
  }
  return $id;
}

// $total is an array holding last received values per inverter, indexed by
// inverter id. Each value is an array of name => value mappings, where name is:
// TS, Energy, Power array, Temp, Volt, State
$total = array();
// When did we last send to PVOUtput?
$last = 0;
// When did we last send a reply back to the gateway?
$lastkeepalive = 0;
// What was the previous nonzero count?
$lastnonzero = 0;
// when did we last update the MQTT output topics?
$lastmqtt = 0;

/*
 * Compute aggregate info to send to PVOutput
 * See http://pvoutput.org/help.html#api-addstatus
 */
function submit($total, $systemid, $apikey) {
  global $lastnonzero;
  // Compute aggragated data: energy, power, avg temp avg volt
  // Power is avg power over the reporting interval
  $e = 0.0;
  $p = 0.0;
  $temp = 0.0;
  $volt = 0.0;
  $nonzerocount = 0;
  $nonzeros = array();
  $okstatecount = 0;
  $otherstatecount = 0;

  foreach ($total as $inv_id => $t) {
    $e += $t['Energy'];
    $pp = 0;
    foreach ($t['Power'] as $x)
      $pp += $x;
    $p += (double)$pp / count($t['Power']);
    $temp += $t['Temperature'];

    if ($pp > 0) {
      $volt += $t['Volt'];
      $nonzerocount++;
    } else {
      // add zero pwr panel to list of IDs
      $nonzeros[] = map_panel($inv_id);
    }

	// apparently the top bit of State should be ignored? I keep getting 128 codes all the time...
    switch ((0x7F & $t['State'])) {
    case 0:  // normal, supplying to grid
    case 1:  // not enough light
    case 3:  // other low light condition
      $okstatecount++;
      break;
    default:
      $otherstatecount++;
      // send alarm code 100 for bad state, followed by state and key
      alarm('100.'.$t['State'].'.'.map_panel($inv_id));
      break;
   }
  }
  $temp /= count($total);
  if ($nonzerocount > 0)
    $volt /= $nonzerocount;
  $p = round($p);
  
  // little free PVOutput hack - report nonzerocount as temp since I dont care about temperature
  $temp = $nonzerocount;
  // alarm code 101 if nonzero drops, followed by active panel count and ID list of dropped panels
  if ($nonzerocount < $lastnonzero) {
    asort($nonzeros);
    $msg = '';
    foreach($nonzeros as $id) { $msg .= '.'.$id; }
    if(ALARM_ON_DROP)
    	alarm('101.'.$nonzerocount.$msg);
    else
    	report('101.'.$nonzerocount.$msg, LOG_NOTICE);
  }
  $lastnonzero = $nonzerocount;

  if (LIFETIME)
    report(sprintf('=> PVOutput (%s) v1=%dWh v2=%dW v5=%.1fC v6=%.1fV',
      count($total) == 1 ? $systemid : 'A', $e, $p, $temp, $volt), LOG_INFO);
  else
    report(sprintf('=> PVOutput (%s) v2=%dW v5=%.1fC v6=%.1fV',
      count($total) == 1 ? $systemid : 'A', $p, $temp, $volt), LOG_INFO);
  $time = time();
  $data = array('d' => strftime('%Y%m%d', $time),
    't' => strftime('%H:%M', $time),
    'v2' => $p,
    // use v4 to report consumption here..
    'v5' => $temp,
    'v6' => $volt
  );

  // Only send cummulative total energy in LIFETIME mode
  if (LIFETIME) {
    $data['v1'] = $e;
    $data['c1'] = 1;
  }
  if (EXTENDED) {
    report(sprintf('   v7=%d v8=%d v9=%d', $nonzerocount, $okstatecount,
      $otherstatecount), LOG_INFO);
    $data['v7'] = $nonzerocount;
    $data['v8'] = $okstatecount;
    $data['v9'] = $otherstatecount;
  }

  // We have all the data, prepare POST to PVOutput
  $headers = "Content-type: application/x-www-form-urlencoded\r\n" .
    'X-Pvoutput-Apikey: ' . $apikey . "\r\n" .
    'X-Pvoutput-SystemId: ' . $systemid . "\r\n";
  $url = 'http://pvoutput.org/service/r2/addstatus.jsp';
  
  $data = http_build_query($data, '', '&');
  $ctx = array('http' => array(
    'method' => 'POST',
    'header' => $headers,
    'content' => $data));
  $context = stream_context_create($ctx);
  $fp = fopen($url, 'r', false, $context);
  if (!$fp)
    report('POST failed, check your APIKEY=' . $apikey . ' and SYSTEMID=' .
      $systemid, LOG_ERR);
  else {
    $reply = fread($fp, 100);
    report('<= PVOutput ' . $reply, LOG_INFO);
    fclose($fp);
  }

  // Optionally, also to mysql
  if (MODE == 'AGGREGATE' && defined('MYSQLDB')) {
    $mvalues = array(
     'IDDec' => 0,
     'DCPower' => $p, 
     'DCCurrent' => 0,
     'Efficiency' => 0,
     'ACFreq' => 0,
     'ACVolt' => $volt,
     'Temperature' => $temp,
     'State' => 0
    );
    submit_mysql($mvalues, $e);
  }
}

// handle MQTT control input
function procmsg($topic, $msg, $retain) {
	global $mqtt;
	global $debug;
	global $verbose;
	global $panels;
	global $fwdhostporttemp;
	global $total;
// When did we last send to PVOUtput?
	global $last;
// When did we last send a reply back to the gateway?
	global $lastkeepalive;
// What was the previous nonzero count?
	global $lastnonzero;
// when did we last update the MQTT output topics?
	global $lastmqtt;
	$now = time();
	// skip retain flag msgs (LWT usually)
	if($retain)
		return;
	// process by topic
	if($debug) echo 'msg from:'.$topic."\n";
	if ($topic=='e2pv/cmd') {
		if($debug) echo "cmd:".$msg."\n";
		if((empty($msg))|| $msg=='status') {
			$data = new stdClass();
			$data->cmd = "status";
			$data->now = $now;
			$data->last = $last;
			$data->lastkeepalive = $lastkeepalive;
			$data->lastnonzero = $lastnonzero;
			$data->lastmqtt = $lastmqtt;
			$data->total = $total;
			$msg = JSON_encode($data);
			$mqtt->publish('e2pv/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='config') {
			$data = new stdClass();
			$data->cmd = "config";
			$data->debug = $debug;
			$data->verbose = $verbose;
			$data->fwdhostporttmp = $fwdhostporttemp;
			$data->idcount = IDCOUNT;
			$data->apikey = APIKEY;
			$data->systemid = SYSTEMID;
			$data->lifetime = LIFETIME;
			$data->mode = MODE;
			$data->extended = EXTENDED;
			$data->ac = AC;
			$data->domomqtt = DOMOMQTT;
			$data->domoidxsolar = DOMOIDXSOLAR;
			$data->domoidxpanelcnt = DOMOIDXPANELCNT;
			$data->mqtt_interval = MQTT_INTERVAL;
			$data->alarm_cmd = ALARM_CMD;
			$data->alarm_on_drop = ALARM_ON_DROP;
			$data->panels = $panels;
			$msg = JSON_encode($data);
			$mqtt->publish('e2pv/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='debug') {
			$debug = !$debug; // toggle and report debug state
			$data = new stdClass();
			$data->cmd = "debug";
			$data->debug = $debug;
			$msg = JSON_encode($data);
			$mqtt->publish('e2pv/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='verbose') {
			$verbose = !$verbose; // toggle and report verbose state
			$data = new stdClass();
			$data->cmd = "verbose";
			$data->verbose = $verbose;
			$msg = JSON_encode($data);
			$mqtt->publish('e2pv/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
	}
	else {
		// Unknown message source - ignore
		return;
	}
}

// update domoticz via MQTT with live total power and lifetime energy, also panels active count
function update_mqtt($total) {
  global $mqtt;
  // Compute sum of: energy, power, voltage
  // Power is most recent live value
  $e = 0.0;
  $p = 0.0;
  $v = 0.0;
  $nonzerocount = 0;
  
  foreach ($total as $inv_id => $t) {
    $e += $t['Energy'];
    $pp = $t['Power'][count($t['Power'])-1]; // careful! this only works for auto-indexed arrays like this one
	$p += $pp;
	$v += $t['Volt'];

    if ($pp > 0) {
      $nonzerocount++;
    }
  }
  
  // send energy data to MQTT
	$data = new stdClass();
	$data->idx = DOMOIDXSOLAR;
	$data->nvalue = 0;
	$data->svalue = $p.';'.$e;
	$msg = JSON_encode($data);
	$mqtt->publish('domoticz/in',$msg,0);
	report('send to domo:'.$msg,LOG_DEBUG);
  // send panel count to MQTT
	$data = new stdClass();
	$data->idx = DOMOIDXPANELCNT;
	$data->nvalue = 0;
	$data->svalue = strval($nonzerocount);  // needs to be a string
	$msg = JSON_encode($data);
	$mqtt->publish('domoticz/in',$msg,0);
	report('send to domo:'.$msg,LOG_DEBUG);
  // send voltage & power to simple topics 'e2pv/voltage' & 'e2pv/solarpower' if at least one panel is reporting
	if (count($total) > 0) {
		$v = $v / count($total);
		$mqtt->publish('e2pv/voltage',$v,0);
		report('send to e2pv/voltage:'.$v,LOG_DEBUG);
		$mqtt->publish('e2pv/solarpower',$p,0);
		report('send to e2pv/solarpower:'.$p,LOG_DEBUG);
	}
  
}

/*
 * Read data from socket until a "\r" is seen
 * Also poll MQTT input queue here to avoid high latency on message handling
 */
$buf = '';
function reader($socket) {
  global $buf;
  global $mqtt;
  $last_read = time();
  while (true) {
    // calll MQTT proc() to process input queue - NOTE this will reconnect if socket is closed
    if(defined('DOMOMQTT')) $mqtt->proc(false);
    $pos = strpos($buf, "\r");
    if ($pos === false) {
      // report('reader():call socket_recv()', LOG_DEBUG); //really noisy, dont enable unless desperate! 
      $ret = @socket_recv($socket, $str, 128, 0);
      if ($ret === false || $ret == 0) {
        if ($last_read <= time() - 90)
          return false;
        sleep(1); // don't hog CPU!
        continue;
      }
      report('reader():got:'.$str, LOG_DEBUG);
      $last_read = time();
      $buf .= $str;
      continue;
    } else {
      $str = substr($buf, 0, $pos + 1);
      $buf = substr($buf, $pos + 2);
	  // forward message if required
	  fwd_msg($str);
      return $str;
    }
  }
}

/*
 * Submit data to MySQL
 */
$link = false;
function submit_mysql($v, $LifeWh) {
  global $link;

  if (!$link) {
    $link = mysqli_connect(MYSQLHOST, MYSQLUSER, MYSQLPASSWORD, MYSQLDB,
      MYSQLPORT);
  }
  if (!$link) {
    report('Cannot connect to MySQL ' . mysqli_connect_error(), LOG_ERR);
    return;
  }

  $query = 'INSERT INTO enecsys(' .
    'id, wh, dcpower, dccurrent, efficiency, acfreq, acvolt, temp, state) ' .
     'VALUES(%d, %d, %d, %f, %f, %d, %f, %f, %d)';
  $q = sprintf($query,
    mysqli_real_escape_string($link, $v['IDDec']),
    mysqli_real_escape_string($link, $LifeWh),
    mysqli_real_escape_string($link, $v['DCPower']),
    mysqli_real_escape_string($link, $v['DCCurrent']),
    mysqli_real_escape_string($link, $v['Efficiency']),
    mysqli_real_escape_string($link, $v['ACFreq']),
    mysqli_real_escape_string($link, $v['ACVolt']),
    mysqli_real_escape_string($link, $v['Temperature']),
    mysqli_real_escape_string($link, $v['State']));

  if (!mysqli_query($link, $q)) {
   report('MySQL insert failed: ' . mysqli_error($link), LOG_ERR);
   mysqli_close($link);
   $link = false;
  }
}

/*
 * Loop processing lines from the gatway
 */
function process($socket) {
  global $verbose, $total, $last, $lastkeepalive, $systemid, $apikey, $ignored, $lastmqtt;

  while (true) {
    $str = reader($socket);
    if ($str === false) {
        report('process():read failed', LOG_DEBUG);
        return;
    }
	$time = time();
    // Send a reply if the last reply is 200 seconds ago
    if ($lastkeepalive < $time - 200) {
      report('process():send keepalive', LOG_DEBUG);
      if (socket_write($socket, "0E0000000000cgAD83\r") === false)
        return;
      //echo 'write done' . PHP_EOL;
      $lastkeepalive = $time;
    }
    $str = str_replace(array("\n", "\r"), "", $str);
    report('process():'.$str, LOG_DEBUG);

    // If the string contains WS, we're interested
    $pos = strpos($str, 'WS');
    if ($pos !== false) {
      $sub = substr($str, $pos + 3);
      // Standard translation of base64 over www
      $sub = str_replace(array('-', '_' , '*'), array('+', '/' ,'='), $sub);
      //report(strlen($sub) . ' ' . $sub);
      $bin = base64_decode($sub);
      // Incomplete? skip
      if (strlen($bin) != 42) {
        report('Unexpected length ' . strlen($bin) . ' skip...', LOG_DEBUG);
        continue;
      }
      //echo bin2hex($bin) . PHP_EOL;
      $v = unpack('VIDDec/c18dummy/CState/nDCCurrent/nDCPower/' .
         'nEfficiency/cACFreq/nACVolt/cTemperature/nWh/nkWh', $bin);
      $id = $v['IDDec'];

      if (in_array($id, $ignored))
        continue;
      if (MODE == 'SPLIT' && !isset($systemid[$id])) {
        report('SPLIT MODE and inverter ' . $id . ' not in $systemid array', LOG_NOTICE);
        continue;
      }
      $v['DCCurrent'] *= 0.025;
      $v['Efficiency'] *= 0.001;
      $LifeWh = $v['kWh'] * 1000 + $v['Wh'];
      $ACPower = $v['DCPower'] * $v['Efficiency'];
      $DCVolt = $v['DCCurrent']? $v['DCPower'] / $v['DCCurrent'] : 0;

      // Clear stale entries (older than 1 hour) 
      foreach ($total as $key => $t) {
        if ($total[$key]['TS'] < $time - 3600) {
          unset($total[$key]);
        }
      }

      // Record in $total indexed by id: cummulative energy
      $total[$id]['Energy'] = $LifeWh;
      // Record in $total, indexed by id: count, last 10 power values
      // volt and temp
      if (!isset($total[$id]['Power'])) {
        $total[$id]['Power'] = array();
      }
      // pop oldest value
      if (count($total[$id]['Power']) > 10)
        array_shift($total[$id]['Power']);
      $total[$id]['Power'][] = AC ? $ACPower : $v['DCPower'];
      $total[$id]['Volt'] = $v['ACVolt'];
      $total[$id]['Temperature'] = $v['Temperature'];
      $total[$id]['State'] = $v['State'];

      if($verbose) {
        $str = sprintf('%s DC=%03dW %05.2fV %04.2fA AC=%03dV %06.2fW E=%04.2f T=%02d S=%03d L=%08.3fkWh',
        map_panel($id), $v['DCPower'], $DCVolt, $v['DCCurrent'],
        $v['ACVolt'], $ACPower,
        $v['Efficiency'], $v['Temperature'], $v['State'],
        $LifeWh / 1000);
		// check report is valid - we seem to get garbage/cutoff strings when inverters shut down?
		// if(strlen($str)==75)
			report($str, LOG_INFO);
      }

      if (defined('MYSQLDB'))
        submit_mysql($v, $LifeWh);
	
	  if (defined('DOMOMQTT') && count($total) == IDCOUNT && $lastmqtt < $time - MQTT_INTERVAL) { // only start sending if we have all inverter data
		update_mqtt($total);
		$lastmqtt = $time;
	  }

      if (MODE == 'SPLIT') {
        // time to report for this inverter?
        if (!isset($total[$id]['TS']) || $total[$id]['TS'] < $time - 540) {
          $key = isset($apikey[$id]) ? $apikey[$id] : APIKEY;
          submit(array($total[$id]), $systemid[$id], $key);
          $total[$id]['TS'] = $time;
        }
      } 
      // for AGGREGATE, only report if we have seen all inverters
      if (count($total) != IDCOUNT) {
        report('Expecing IDCOUNT=' . IDCOUNT . ' IDs, seen ' .
          count($total) . ' IDs', LOG_NOTICE);
      } elseif ($last < $time - 540) {
        submit($total, SYSTEMID, APIKEY);
        $last = $time;
      }
      if (MODE == 'AGGREGATE')
        $total[$id]['TS'] = $time;
    }
  }
}

/*
 * Setup a listening socket
 */
function setup() {
  $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  if ($socket === false)
    fatal('socket_create');
  // SO_REUSEADDR to make fast restarting of script possible
  socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
  $ok = socket_bind($socket, '0.0.0.0', 5040);
  if (!$ok) 
    fatal('socket_bind');
  // backlog of 1, we do not serve multiple clients
  $ok = socket_listen($socket, 1);
  if (!$ok)
    fatal('socket_listen');
  return $socket;
}

/*
 * Loop accepting connections from the gatwway
 */
function loop($socket) {
  $errcount = 0;
  while (true) {
    report('open listen socket', LOG_DEBUG);
    $client = socket_accept($socket);
    if (!$client) {
      report('Socket_accept: ' . socket_strerror(socket_last_error()), LOG_INFO);
      if (++$errcount > 100)
        fatal('Too many socket_accept errors in a row');
      else
        continue;
    }
    $errcount = 0;
    if (!socket_set_nonblock($client))
      fatal('socket_set_nonblock');
    socket_getpeername($client, $peer);
    report('Accepted connection from ' . $peer, LOG_INFO);
    process($client);
    socket_close($client);
    report('Connection closed', LOG_INFO); 
  }
}

if (isset($_SERVER['REQUEST_METHOD'])) {
  fatal('only command line');
}

if (!defined('LIFETIME') || (LIFETIME !== 0 && LIFETIME !== 1)) {
  fatal('LIFETIME should be defined to 0 or 1');
}
if (!defined('EXTENDED') || (EXTENDED !== 0 && EXTENDED !== 1)) {
  fatal('EXTENDED should be defined to 0 or 1');
}
if (!defined('MODE') || (MODE != 'SPLIT' && MODE != 'AGGREGATE')) {
  fatal('MODE should be \'SPLIT\' or \'AGGREGATE\'');
}
if (!defined('AC') || (AC !== 0 && AC !== 1)) {
  fatal('AC should be defined to 0 or 1');
}
if (MODE == 'SPLIT' && count($systemid) != IDCOUNT) {
  fatal('In SPLIT mode, define IDCOUNT systemid mappings');
}
 
report("e2pv starting up - hello!", LOG_NOTICE);
$socket = setup();
if(defined('DOMOMQTT')) {
	report("e2pv connect to MQTT",LOG_NOTICE);
	$mqtt->connect_auto(true, NULL, NULL, NULL);
	$topics['e2pv/cmd'] = array("qos" => 0, "function" => "procmsg");
	$mqtt->subscribe($topics, 0);
	// post first status
	$mqtt->publish('e2pv/cmd','status',0);
}
loop($socket);
socket_close($socket);
if($fwdsock) socket_close($fwdsock);
if(defined('DOMOMQTT')) $mqtt->close();
report('e2pv exiting - byebye!', LOG_NOTICE);

?>
