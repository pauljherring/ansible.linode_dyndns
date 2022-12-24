#!/usr/bin/php
<?php
	$verbosity = LOG_DEBUG;
	$ip_methods = [ 'local', 'ipv.me', 'vpn'];

	/** @brief INI file handler */

	class IniFile {
		private $configuration;
		private $filename;

		public function __construct($filename=null){
			$this->filename = $filename;
			$this->configuration = null;
		}

		/** @brief read ini file
		 * @param string $filename
		 * 	filename to parse if it's not the one passed into the ctor.
		 * @retval false|array
		 * 	false if the config couldn't be read for some reason, the config otherwise
		 */
		public function read($file=null){
			if (is_null($file))
				$file = $this->filename;
			if (!file_exists($file)){
				return false;
			}
			if ( ($a = parse_ini_file($file, true)) === false){
				return false;
			}
			return ($this->configuration = $a);
		}

		/** @brief output the current configuration as an array with suitably escaped values
		*/
		function raw(){
			if (is_null($this->configuration)){
				if (!$this->read())
					return false;
			}
			$res = array();
			foreach($this->configuration as $key => $val){
				if(is_array($val)){
					$res[] = "[$key]";
					foreach($val as $skey => $sval)
						$res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
				}else{
					$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
				}
			}
			return implode("\r\n", $res);
		}

		/** @brief write the config out to file
		*	@param string $file
		*		file to write to, if specified
		*/
		function write($file=null){
			if (is_null($file))
				$file = $this->filename;
			return self::safefilerewrite($file, $this->raw());
		}

		/** @brief Write data to a file, with file locks
		*		@param string $filename
		*		@param string $dataToSave
		*/
		private function safefilerewrite($fileName, $dataToSave){
			$canWrite = false;

			$fp = fopen($fileName, 'w');
			if (false === $fp){
				return false;
			}

			$startTime = microtime(TRUE);
			do{
				$canWrite = flock($fp, LOCK_EX);
				// If lock not obtained sleep for 0 - 100 milliseconds, to avoid collision and CPU load
				if(!$canWrite)
					usleep(round(rand(0, 100)*1000));
			} while ((!$canWrite)and((microtime(TRUE)-$startTime) < 5));

			//file was locked so now we can store information
			if ($canWrite){
				fwrite($fp, $dataToSave);
				flock($fp, LOCK_UN);
			}
			fclose($fp);
			return $canWrite;
		}

		/** @brief change the currently stored configuration
		*/
		function set($configuration){
			$this->configuration = $configuration;
			return true;
		}

	}

	/** Get the config
	*/
	function get_config(string $section){
		// where are we storing config? ()
		global $argv;
		$config_ini = (getenv('XDG_DATA_HOME')?:getenv('HOME')."/.local/share").'/'.pathinfo($argv[0])['filename'].'.ini';
		log_message(LOG_INFO, "Reading [$section] from $config_ini");
		if (!file_exists(dirname($config_ini))){
			if (!mkdir(dirname($config_ini), 0700, true)){
				log_message(LOG_CRIT, "Cannot create configuration directory '".dirname($config_ini)."' (for $config_ini)");
				exit(-1);
			}
		}
		$ini = new IniFile($config_ini);
		if (!($config = $ini->read()) || empty($config[$section])){
			echo "I can't seem to find a configuration file at $config_ini...\n";
			echo "Please enter the hostname to be resolved, the domain to which it belongs and the token required to be able to update\n";
			echo "e.g. for the FQDN 'sofa.example.com':\n";
			echo " - sofa is the hostname\n";
			echo " - example.com is the domain\n";
			// require entry in order of checks below
			$config[$section]['token'] = strtolower(readline("Please enter your API key: "));
			$config[$section]['host'] = strtolower(readline("Please enter the host name (only): "));
			$config[$section]['domain'] = readline("Please enter the domain-name (without the hostname): ");

			global $ip_methods;
			foreach($ip_methods as $method){
				$ipv4 = my_ipv4($method);
				$ipv6 = my_ipv6($method);
				echo " - Method: '$method' => $ipv4 $ipv6\n";
			}
			$config[$section]['method'] = readline("Please enter one of the above methods to detect the IP address from the above list: ");

			echo "Performing sanity checks...";
			if (!in_array($config[$section]['method'], $ip_methods)){
				log_message(LOG_CRIT, "Unrecognised IP retreival method: ".$config[$section]['method']);
				exit(-1);
			}
			echo " .. method OK\n";
			// checks in order of speed - the dns lookup is slow.
			/* Host checks */
			// if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', $config[$section]['host'])){
			// 	log_message(LOG_CRIT, "Problem with hostname '".$config[$section]['host']."' - should be 1-63 characters, contain only [a-z, 0-9, -] and mustn't start or end with a -");
			// 	exit(-1);
			// }
			echo " .. hostname OK\n";

			/* API checks */
			if (!preg_match('/^[a-z0-9]{64}$/', $config[$section]['token'])){
				log_message(LOG_CRIT, "Problem with API key - expecting 64 hexadecimal characters");
				exit(-1);
			}
			echo " .. API format OK\n";

			/* Domain checks */
			if (false === ($dns = dns_get_record($config[$section]['domain'], DNS_A+DNS_AAAA))){
				log_message(LOG_CRIT, "Cannot locate any existing DNS A or AAAA records for the ".$config[$section]['domain']." zone - are you sure it's a real domain?");
				exit(-1);
			}
			foreach($dns as $r){
				switch ($r['type']){
					case 'A':
						echo " .. .. Found IPv4 for ".$config[$section]['domain'].": ".$r['ip']."\n";
						break;
					case 'AAAA':
						echo " .. .. Found IPv6 for ".$config[$section]['domain'].": ".$r['ipv6']."\n";
						break;
				}
			}
			echo " .. ip address OK\n";
			if (!($d = domain($config[$section]))){
				log_message(LOG_CRIT, "That API key doesn't work for ".$config[$section]['domain']);
				exit(-1);
			}
			echo " .. query Linode OK\n";
			if (!$ini->set($config) || !$ini->write()){
				log_message(LOG_CRIT, "Cannot read or create ini file at '".$config_ini."'");
				exit(-1);
			}
			echo " .. config written\n";
		}
		return $config;
	}

	/** @brief return the specified number of ancestors to a path.
	 * @param string path
	 *      The path to process
	 * @param integer n
	 *      The number of ancestors to return.
	 * @retval
	 *      the parent, grandparent, great-grandparent etc.. of the path supplied.
	 *
	 * A trailling / will presume the last component is the parent, otherise it will be
	 * considered a child and ignored.
	 */
	function ancestornames($path, $n = 1){
		$ret = "";
		$relative = 1;
		if (substr($path, -1) === '/'){ // add suffix leaf
			$path.='dummy.file';
		}
		if (substr($path, 0, 1) == '/'){
			$relative = 0;
		}
		$components = array_reverse(explode('/', $path));
		array_shift($components);
		while(count($components) && $n-->0){
			$component = array_shift($components);
			if ($component > ''){
				if ($ret > '')
					$ret = '/'.$ret;
				$ret = $component.$ret;
			}
		}
		if (isset($components[0]) && $components[0]=='' && !$relative)
				$ret = '/'.$ret;
		return $ret;
	}

	/** @brief log a message. Somewhere
	*/
	function log_message($priority, $message){
		global $verbosity;
		$backtrace = debug_backtrace();
		$file=ancestornames($backtrace[0]['file'].'/',3);
		$line=$backtrace[0]['line'];
		if (isset($backtrace[1]))
			$function=$backtrace[1]['function'];
		else
			$function="";

		syslog($priority, $message);
		if ($priority <= $verbosity){
			fwrite(STDERR, "$message".PHP_EOL);
		}
		return false;
	}

	/** @brief wrapper to try and run stuff.
	 *
	 * We basically want exec(), but if that's disabled/missing, try a couple of other
	 * methods
	 */
	function test_func($func, &$func_used){
		if (function_exists($func)){
			if ($func_used != $func){
				$func_used = $func;
				// log_message(LOG_DEBUG, "Using $func_used");
			}
			return true;
		}
		return false;
	}
	function run_command($command, &$output, &$retval){
		static $func_used="";
		if (test_func('exec', $func_used)) {
			$output=[];
			$retval=-1;
			exec($command, $output, $retval);
			return;
		}
		if (test_func('popen', $func_used)){
			$retval=-1;
			if (false === ($handle = popen($command, 'r'))){
					$output="";
					return;
			}
			$output = array(fread($handle, 2048));
			$retval = pclose($handle);
			return;
		}
		if (test_func('system', $func_used)){
			$retval=-1;
			ob_start(); //https://stackoverflow.com/a/6708160
			$output=array(system($command, $retval));
			ob_clean();
			return;
		}
		quit(126, LOG_CRIT, "exec(),popen() and system()  are disabled/missing - unable to function");
	}

	// http://thisinterestsme.com/php-post-request-without-curl/
	/**
	 * Send a POST request without using PHP's curl functions.
	 *
	 * @param string $url The URL you are sending the POST request to.
	 * @param array $postVars Associative array containing POST values.Mutually exclusive with postData
	 * @param string $postData Raw data to post. Mutually exclusive with postVars
	 * @return string The output response.
	 * @throws Exception If the request fails.
	 */
	function http_rest($method, $TOKEN, $url, $postVars = null, $postData=null){
		$postStr = '';
	    if (!is_null($postVars)){
			//Transform our POST array into a URL-encoded query string.
	    	$postStr = http_build_query($postVars);
		}else if (!is_null($postData)){
			$postStr = $postData;
		}
	    //Create an $options array that can be passed into stream_context_create.
	    $options = array(
	        'http' =>[
	                'method'  => $method,
	                'header'  => [
						'Authorization: Bearer '.$TOKEN,
						'Content-type: application/json'
					],
	                'content' => $postStr //Our URL-encoded query string.
	            ]);
	    //Pass our $options array into stream_context_create.
	    //This will return a stream context resource.
	    $streamContext  = stream_context_create($options);
	    //Use PHP's file_get_contents function to carry out the request.
	    //We pass the $streamContext variable in as a third parameter.
	    $result = file_get_contents($url, false, $streamContext);
	    //If $result is FALSE, then the request has failed.
	    if($result === false){
	        //If the request failed, throw an Exception containing
	        //the error.
	        $error = error_get_last();
	        throw new Exception($method.' request failed for '.$url.': '."\n".$postStr."\n". $error['message']);
	    }
	    //If everything went OK, return the response.
	    return $result;
	}

	function post($TOKEN, $url, $postVars = null, $postData=null){
		return http_rest('POST', $TOKEN, $url, $postVars, $postData);
	}
	function get($TOKEN, $url, $postVars = null, $postData=null){
		return http_rest('GET', $TOKEN, $url, $postVars, $postData);
	}
	function put($TOKEN, $url, $postVars = null, $postData=null){
		return http_rest('PUT', $TOKEN, $url, $postVars, $postData);
	}
	function delete($TOKEN, $url, $postVars = null, $postData=null){
		return http_rest('DELETE', $TOKEN, $url, $postVars, $postData);
	}

	function domain($site){
		$page=1;
		while (true){
			$domains = json_decode(get($site['token'], 'https://api.linode.com/v4/domains?page='.$page), true);
			if (!($this_page = $domains['page'])){
				return log_message(LOG_ERR, 'page missing from domains reply');
			}
			if (!($total_pages = $domains['pages'])){
				return log_message(LOG_ERR, 'page count missing from reply');
			}
			foreach($domains['data'] as $domain){
				if (!strcasecmp($domain['domain'], $site['domain'])){
					return $domain;
				}
			}
			if ($this_page >= $total_pages){
				break;
			}
			$page++;
		}
		return log_message(LOG_ERR, 'domain "'.$site['domain'].'" not found');
	}

	function domain_record($site, $d, $type){
		$page=1;
		while (true){
			$domain_records = json_decode(get($site['token'], 'https://api.linode.com/v4/domains/'.$d['id'].'/records?page='.$page), true);
			if (!($this_page = $domain_records['page'])){
				return log_message(LOG_ERR, 'page missing from records reply');
			}
			if (!($total_pages = $domain_records['pages'])){
				return log_message(LOG_ERR, 'page count missing from records reply');
			}
			foreach($domain_records['data'] as $record){
				if (!strcasecmp($record['name'], $site['host']) && !strcasecmp($record['type'], $type)){
					return $record;
				}
			}
			if ($this_page >= $total_pages){
				break;
			}
			$page++;
		}
		return log_message(LOG_ERR, $type.' record for "'.$site['host'].'.'.$site['domain'].'" ('.$d['domain'].'/'.$d['id'].') not found');
	}

	function local_ipv4(){
		$ip = '198.18.61.34'; // RFC 6815 - block shouldn't be used anywhere else
		run_command('ip route get 8.8.8.8 | sed -n \'s/^.*src \([0-9\.]*\).*/\1/gp\'', $output, $worked);
		if ($worked === 0){
			$ip = $output[0];
		}
		return $ip;
	}

	function local_vpn($gateway){
		if (empty($gateway)) {
			$gateway = '172.20.0.0';
		}
		$ip = '198.18.61.34'; // RFC 6815 - block shouldn't be used anywhere else
		run_command('ip route get ' . $gateway . ' | sed -n \'s/^.*src \([0-9\.]*\).*/\1/gp\'', $output, $worked);
		if ($worked === 0){
			$ip = $output[0];
		}
		return $ip;
	}

	function local_ipv6(){
		$ip = '2001:0200::1'; // RFC 5180 - block shouldn't be used anywhere else
		run_command('2>/dev/null ip -6 route get 2001:4860:4860::8888 | sed -n \'s/^.*src \([0-9a-fA-F:\.]*\).*/\1/gp\'', $output, $worked);
		if ($worked === 0){
			$ip = $output[0]??false;
		}
		return $ip;

	}

	function my_ipv4($method='google', $gateway = ''){
		$ret = false;
		error_reporting( ($old_er = error_reporting()) & ~E_WARNING); // stop warnings appearing on file_get_contents
		switch ($method){
			case 'local':
				return local_ipv4();
			case 'vpn':
				return local_vpn($gateway);
			case 'ipv.me':
				if ( ($output = file_get_contents('http://ip4.me/api/')) ){
					if (($split = explode(',', $output)) && isset($split[1]) && ($split[0]=='IPv4')){
						// IPv4,45.33.54.82,Remaining fields reserved for future use,,,
						$ret = $split[1];
					}
				}
		}
		return $ret;
	}

	function my_ipv6($method='google'){
		$ret = false;
		error_reporting( ($old_er = error_reporting()) & ~E_WARNING); // stop warnings appearing on file_get_contents
		switch ($method){
			case 'local':
				return local_ipv6();
			case 'ipv.me':
				if ( ($output = file_get_contents('http://ip6only.me/api/')) ){
					if (($split = explode(',', $output)) && isset($split[1]) && ($split[0]=='IPv6')){
						// IPv6,2600:3c01::f03c:91ff:fe31:5937,Remaining fields reserved for future use,,,
						$ret = $split[1];
					}
				}
		}
		error_reporting($old_er);
		return $ret;
	}

	function add_a($site, $d, $ip){
		$json = '{ "type": "A", "name": "'.$site['host'].'", "target": "'.$ip.'", "ttl_sec": '.($site['ttl_sec']??300).'}';
		$r = json_decode(post($site['token'], 'https://api.linode.com/v4/domains/'.$d['id'].'/records', null, $json), true);
		if ($r['ttl_sec'] != ($site['ttl_sec']??300)){
			replace_a($site, $d, $r, $ip);
		}else{
			echo print_r($r, 1)."\n";
		}
	}
	function replace_a($site, $d, $r, $ip){
		$json = '{ "name": "'.$site['host'].'", "target": "'.$ip.'", "ttl_sec": '.($site['ttl_sec']??300).'}';
		$r = json_decode(put($site['token'], 'https://api.linode.com/v4/domains/'.$d['id'].'/records/'.$r['id'], null, $json), true);
		echo print_r($r, 1)."\n";
	}
	function add_aaaa($site, $d, $ip){
		$json = '{ "type": "AAAA", "name": "'.$site['host'].'", "target": "'.$ip.'", "ttl_sec": '.($site['ttl_sec']??300).'}';
		$r = json_decode(post($site['token'], 'https://api.linode.com/v4/domains/'.$d['id'].'/records', null, $json), true);
		if ($r['ttl_sec'] != ($site['ttl_sec']??300)){
			replace_a($site, $d, $r, $ip);
		}else{
			echo print_r($r, 1)."\n";
		}
	}
	function replace_aaaa($site, $d, $r, $ip){
		$json = '{ "name": "'.$site['host'].'", "target": "'.$ip.'", "ttl_sec": '.($site['ttl_sec']??300).'}';
		$r = json_decode(put($site['token'], 'https://api.linode.com/v4/domains/'.$d['id'].'/records/'.$r['id'], null, $json), true);
		echo print_r($r, 1)."\n";
	}

	/**
	 * Version of array_key_exists() that returns a default value
	 * @param int|string $key
	 * @param array<mixed> $array
	 * @param mixed|null $default
	 * @return mixed
	 */
	function arrayKeyExistsOr($key, array $array, $default = null)
	{
		if (!array_key_exists($key, $array)) {
			return $default;
		}
		return $array[$key];
	}

	$section = $argv[1]??'default';
	$config = get_config($section);

	$site=$config[$section];
	// var_dump($site);

	$d = domain($site);
	if ( ($ip = my_ipv4($site['method'], arrayKeyExistsOr('gateway', $site, '')))) {
		if (!($r = domain_record($site, $d, 'A'))){
			echo "Adding IPv4: $ip \n";
			add_a($site, $d, $ip);
		}else{
			if (($r['target'] != $ip) || ($r['ttl_sec'] != ($site['ttl_sec']??300))){
				echo "Replacing IPv4: $ip/".$r['target']." - ".$r['ttl_sec'].':'.($site['ttl_sec']??300)."\n";
				replace_a($site, $d, $r, $ip);
			}else{
				echo "No need to change record: ".$site['host'].".".$site['domain'].":".$ip.' - '.$r['ttl_sec']."\n";
			}
		}
	}
	if ( ($ip = my_ipv6($site['method']))){
		if (!($r = domain_record($site, $d, 'AAAA'))){
			echo "Adding IPv6: $ip \n";
			add_aaaa($site, $d, $ip);
		}else{
			if (($r['target'] != $ip) || ($r['ttl_sec'] != ($site['ttl_sec']??300))){
				echo "Replacing IPv6: $ip $r\n";
				replace_aaaa($site, $d, $r, $ip);
			}else{
				echo "No need to change record: ".$site['host'].".".$site['domain'].":".$ip.' - '.$r['ttl_sec']."\n";
			}
		}
	}
