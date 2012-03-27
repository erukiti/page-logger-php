<?php

	class PageLoggerOutput_Wildfire extends PageLoggerOutputBuffered {
		function __construct($opt) {
			parent::__construct($opt);
			$this->msg_index = 1;
		}

		private function wf_raw($meta, $var) {
			$structure_index = 1;
			$msg = '['.json_encode($meta).','.json_encode($var).']';
			$msg_length = strlen($msg);
			header("X-Wf-1-{$structure_index}-1-{$this->msg_index}: {$msg_length}|{$msg}|");
			$this->msg_index++;
		}

		private function wf_group_start($meta = null, $is_collapsed = true, $label = null) {
			if (!$meta)
				$meta = array();
			$meta['Type'] = 'GROUP_START';
			$meta['Collapsed'] = $is_collapsed ? 'true' : 'false';
			if ($label)
				$meta['Label'] = $label;
			$this->wf_raw($meta, null);
		}

		private function wf_group_end($meta = null) {
			if (!$meta)
				$meta = array();
			$meta['Type'] = 'GROUP_END';
			$this->wf_raw($meta, null);
		}

		private function wf_output($meta, $var, $label = null) {
			if ($label)
				$meta['Label'] = $label;

			switch (gettype($var)) {
			case 'array':
				$label = "{$meta['Label']} (" . count($var) . ")";
				$this->wf_group_start($meta, true, $label);
				foreach ($var as $label => $var2) {
					$this->wf_output(array_merge($meta, array('Label' => "{$label}")), $var2);
				}
				$this->wf_group_end($meta);
				break;
			case 'boolean':
			case 'integer':
			case 'double':
			case 'NULL':
				$this->wf_raw($meta, $var);
				break;
			case 'string':
				$encoding = mb_detect_encoding($var);
				if ($encoding == 'ASCII')
					$this->wf_raw($meta, "\"{$var}\"");
				elseif ($encoding == 'UTF-8')
					$this->wf_raw($meta, "(utf-8) \"{$var}\"");
				elseif ($encoding === false)
					$this->wf_raw($meta, "(unknown) \"{$var}\"");
				else
					$this->wf_raw($meta, "({$encoding}) \"". mb_convert_encoding($var, 'utf-8', $encoding) . '"');
				
				break;
			case 'resource':
				$this->wf_raw($meta, get_resource_type($var) . ":{$var}");
				break;
			case 'object':
				$label = $meta['Label'] . ' (object) ' . get_class($var);
				if (get_parent_class($var)) {
					$label .= ' extends ' . get_parent_class($var);
				}
				$this->wf_group_start($meta, true, $label);
				$this->wf_output($meta, get_class_methods($var), 'method');
				$this->wf_output($meta, get_object_vars($var), 'vars');
				$this->wf_group_end($meta);
				break;
			}
		}

		function get_type($level) {
			switch ($level) {
			case 1:
				return 'LOG';
			case 2:
				return 'INFO';
			case 3:
				return 'WARN';
			case 4:
				return 'ERROR';
			}
			return 'LOG';
		}

		function flush() {
			if (isset($this->application) && $this->application) {
				foreach ($this->application as $label => $var) {
					$this->wf_output(array('Type' => 'INFO', 'Label' => "{$label}"), $var);
				}
			}

			// log_output
			$this->wf_group_start(null, false, 'log');
			foreach ($this->log_buffer as $log) {
				$msg_meta = array('Type' => $this->get_type($log['level']), 'File' => $log['file'], 'Line' => $log['line'], 'Label' => "{$log['file']}:{$log['line']}");
				$this->wf_output($msg_meta, $log['var']);
			}
			$this->wf_group_end();

			$msg_index = $this->msg_index - 1;
			header('X-Wf-Protocol-1: http://meta.wildfirehq.org/Protocol/JsonStream/0.2');
			header('X-Wf-1-Plugin-1: http://meta.firephp.org/Wildfire/Plugin/FirePHP/Library-FirePHPCore/0.3');
			header('X-Wf-1-Structure-1: http://meta.firephp.org/Wildfire/Structure/FirePHP/FirebugConsole/0.1');
			header("X-Wf-1-Index: {$msg_index}");
		}
	}

	class PageLoggerOutput_Fluent extends PageLoggerOutputBuffered {
		private function tag_name() {
			switch ($this->level) {
			case 0:
			default:
				break;
			case 1:
				$tag_level = PageLogger::NAME1;
				break;
			case 2:
				$tag_level = PageLogger::NAME2;
				break;
			case 3:
				$tag_level = PageLogger::NAME3;
				break;
			case 4:
				$tag_level = PageLogger::NAME4;
				break;
			}
			return "{$this->tag_name}.{$tag_level}";
		}

		function __construct($opt) {
			parent::__construct($opt);
			$host = isset($opt['host']) ? $opt['host'] : "127.0.0.1";
			$port = isset($opt['port']) ? $opt['port'] : 24224;
			$name = isset($opt['name']) ? $opt['name'] : 'Pagelog';
			$this->uri = "tcp://{$host}:{$port}";
			$this->tag_name = $name;
		}

		function flush() {
			if ($this->level <= 0) {
				return;
			}

			$var = array('application' => $this->application, 'log' => $this->log_buffer);

			$socket = @stream_socket_client($this->uri, $errno, $errstr, 3, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT);
			if (!$socket) {
		    	$errors = error_get_last();
				echo ($errors['message']);
			}
			stream_set_timeout($socket, 3);

			$packed = json_encode(array($this->tag_name(), time(), $var));
			fwrite($socket, $packed);
			fclose($socket);
		}
	}

	class PageLoggerOutputBuffered {
		function __construct($opt) {
			$this->log_buffer = array();
			$this->application = null;
			$this->level = 0;
		}

		function log($log) {
			$this->log_buffer[] = $log;
			if ($this->level < $log['level']) {
				$this->level = $log['level'];
			}
		}

		function set_application($app) {
			$this->application = $app;
		}

		function flush() {
		}
	}

	class PageLogger {
		Const DEBUG = 1;
		Const INFO = 2;
		Const ERROR = 3;
		Const FATAL = 4;

		Const NAME1 = "debug";
		Const NAME2 = "info";
		Const NAME3 = "error";
		Const NAME4 = "fatal";

	// ------------------
		private static $logger = array();
		private static $opt = array();

		private function log($level, $var, $nest) {
			$bt = debug_backtrace();
			$nest++;

			$log = array('level' => $level, 'file' => $bt[$nest]['file'], 'line' => $bt[$nest]['line'], 'var' => $var);
			if (isset($bt[$nest + 1]['function'])) {
				$log['function'] = $bt[$nest + 1]['function'];
			}

			foreach ($this->output as $output) {
				$output->log($log);
			}
		}

		private function set_application($var) {
			foreach ($this->output as $output) {
				$output->set_application($var);
			}
		}

	// ------------------
		function __construct($opt = null) {
			$this->output = array();

			if (!isset($opt['output'])) {
				$output_list = array();
			} elseif (is_array($opt['output'])) {
				$output_list = $opt['output'];
			} else {
				$output_list = array($opt['output']);
			}
			foreach ($output_list as $output_name) {
				if (isset($opt[$output_name])) {
					$this->output[] = new $output_name($opt[$output_name]);
				} else {
					$this->output[] = new $output_name(array());
				}
			}
		}

		function __destruct() {
			foreach ($this->output as $output) {
				$output->flush();
			}
		}

		function open($opt = null) {
			if (!$opt) {
				$opt = self::$opt;
			}

			$key = md5(serialize($opt));

			if (!isset(self::$logger[$key])) {
				self::$logger[$key] = new PageLogger($opt);
			}
			return self::$logger[$key];
		}

		function set_opt($opt) {
			self::$opt = $opt;
		}

	// ------------------
		function fatal($message, $nest = 0) {
			if (isset($this)) {
				$this->log(self::FATAL, $message, $nest);
			} else {
				self::open()->log(self::FATAL, $message, $nest);
			}
		}

		function debug($message, $nest = 0) {
			if (isset($this)) {
				$this->log(self::DEBUG, $message, $nest);
			} else {
				self::open()->log(self::DEBUG, $message, $nest);
			}
		}

		function application($app) {
			if (isset($this)) {
				$this->set_application($app);
			} else {
				self::open()->set_application($app);
			}
		}

	// ------------------

    }
