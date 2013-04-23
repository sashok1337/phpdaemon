<?php

/**
 * Process
 *
 * @package Core
 *
 * @author Zorin Vasily <maintainer@daemon.io>
 */
class AsyncProcess extends IOStream {

	/**
	 * Command string
	 * @var string
	 */
	protected $cmd;

	/**
	 * Executable path
	 * @var string
	 */
	protected $binPath;

	/**
	 * Opened pipes
	 * @var array
	 */
	protected $pipes;

	/**
	 * Process descriptor
	 * @var resource
	 */
	protected $pd;

	/**
	 * FD write
	 * @var resource
	 */
	protected $fdWrite;

	/**
	 * Output errors? 
	 * @var boolean
	 */
	protected $outputErrors = false;

	// @todo make methods setUser and setGroup, variables change to $user and $group with null values
	protected $setUser;                               // optinal SUID.
	protected $setGroup;                              // optional SGID.

	// @todo the same, make a method setChroot
	protected $chroot = '/';                          // optional chroot.

	protected $env = [];                      		   // hash of environment's variables

	// @todo setCwd
	protected $cwd;                                   // optional chdir
	protected $errlogfile = '/tmp/cgi-errorlog.log';  // path to error logfile
	protected $args;                                  // array of arguments

	// @todo setNice
	protected $nice;                                  // optional priority

	protected $bev;
	protected $bevWrite;
	protected $bevErr;

	protected $EOF = false;

	public function onReadData($cb = NULL) {
		$this->onReadData = $cb;
		return $this;
	}

	/**
	 * Sets fd
	 * @param mixed File descriptor
	 * @param [object EventBufferEvent]
	 * @return void
	 */	

	public function setFd($fd, $bev = null) {
		$this->fd = $fd;
		if ($fd === false) {
			$this->finish();
			return;
		}
		$this->fdWrite = $this->pipes[0];
		$flags = !is_resource($this->fd) ? EventBufferEvent::OPT_CLOSE_ON_FREE : 0;
		$flags |= EventBufferEvent::OPT_DEFER_CALLBACKS; /* buggy option */
		$this->bev = new EventBufferEvent(Daemon::$process->eventBase, $this->fd, 0, [$this, 'onReadEv'], null, [$this, 'onStateEv']);
		$this->bevWrite = new EventBufferEvent(Daemon::$process->eventBase, $this->fdWrite, 0, null, [$this, 'onWriteEv'], null);
		if (!$this->bev || !$this->bevWrite) {
			$this->finish();
			return;
		}
		if ($this->priority !== null) {
			$this->bev->priority = $this->priority;
		}
		if ($this->timeout !== null) {
			$this->setTimeout($this->timeout);
		}
		if (!$this->bev->enable(Event::READ | Event::TIMEOUT | Event::PERSIST)) {
			$this->finish();
			return;
		}
		if (!$this->bevWrite->enable(Event::WRITE | Event::TIMEOUT | Event::PERSIST)) {
			$this->finish();
			return;
		}
		$this->bev->setWatermark(Event::READ, $this->lowMark, $this->highMark);

		init:
		if (!$this->inited) {
			$this->inited = true;
			$this->init();
		}
	}

	/**
	 * Sets an array of arguments
	 * @param array Arguments
	 * @return object AsyncProccess
	 */
	public function setArgs($args = NULL) {
		$this->args = $args;

		return $this;
	}

	/**
	 * Set a hash of environment's variables
	 * @param array Hash of environment's variables
	 * @return object AsyncProccess
	 */
	public function setEnv($env = NULL) {
		$this->env = $env;

		return $this;
	}

	public function onEofEvent() {
		if ($this->EOF) {
			return;
		}
		$this->EOF = true;

		if ($this->onEOF !== null) {
			call_user_func($this->onEOF, $this);
		}
	}

	/**
	 * Set priority.
	 * @param integer Priority
	 * @return object AsyncProccess
	 */
	public function nice($nice = NULL) {
		$this->nice = $nice;

		return $this;
	}

	/**
	 * Called when new data received
	 * @return boolean
	 */
	protected function onRead() {
		if (func_num_args() === 1) {
			$this->onRead = func_get_arg(0);
			return $this;
		}
		if ($this->onReadData === null) {
			if ($this->onRead !== null) {
				call_user_func($this->onRead, $this);
			}
			return;
		}
		while (($buf = $this->read($this->readPacketSize)) !== false) {
			call_user_func($this->onReadData, $this, $buf);
		}
	}

	/**
	 * Execute
	 * @param string Optional. Binpath.
	 * @param array Optional. Arguments.
	 * @param array Optional. Hash of environment's variables.
	 * @return object AsyncProccess
	 */
	public function execute($binPath = NULL, $args = NULL, $env = NULL) {
		if ($binPath !== NULL) {
			$this->binPath = $binPath;
		}

		if ($env !== NULL) {
			$this->env = $env;
		}

		if ($args !== NULL) {
			$this->args = $args;
		}

		$args = '';

		if ($this->args !== NULL) {
			foreach ($this->args as $a) {
				$args .= ' ' . escapeshellcmd($a);
			}
		}

		$this->cmd = $this->binPath . $args . ($this->outputErrors ? ' 2>&1' : '');

		if (
			isset($this->setUser) 
			|| isset($this->setGroup)
		) {
			if (
				isset($this->setUser) 
				&& isset($this->setGroup) 
				&& ($this->setUser !== $this->setGroup)
			) {
				$this->cmd = 'sudo -g ' . escapeshellarg($this->setGroup). '  -u ' . escapeshellarg($this->setUser) . ' ' . $this->cmd;
			} else {
				$this->cmd = 'su ' . escapeshellarg($this->setGroup) . ' -c ' . escapeshellarg($this->cmd);
			}
		}

		if ($this->chroot !== '/') {
			$this->cmd = 'chroot ' . escapeshellarg($this->chroot) . ' ' . $this->cmd;
		}

		if ($this->nice !== NULL) {
			$this->cmd = 'nice -n ' . ((int) $this->nice) . ' ' . $this->cmd;
		}

		$pipesDescr = array(
			0 => array('pipe', 'r'),  // stdin is a pipe that the child will read from
			1 => array('pipe', 'w'),  // stdout is a pipe that the child will write to
		);

		if (
			($this->errlogfile !== NULL) 
			&& !$this->outputErrors
		) {
			//$pipesDescr[2] = array('file', $this->errlogfile, 'a');
		}

		$this->pd = proc_open($this->cmd, $pipesDescr, $this->pipes);//, $this->cwd, $this->env);
		if ($this->pd) {
			$this->setFd($this->pipes[1]);
		}

		return $this;
	}

	public function finishWrite() {
		if (!$this->writeState) {
			$this->closeWrite();
		}

		$this->finishWrite = true;

		return true;
	}

	/**
	 * Close the process
	 * @return void
	 */
	public function close() {
		parent::close();
		$this->closeWrite();
		if ($this->pd) {
			proc_close($this->pd);
		}
	}

	public function onFinish() {
		$this->onEofEvent();
	}
	public function closeWrite() {
		if ($this->bevWrite) {
			if (isset($this->bevWrite)) {
				$this->bevWrite->free();
			}
			$this->bevWrite = null;
		}

		if ($this->fdWrite) {
			fclose($this->fdWrite);
			$this->fdWrite = null;
		}

		return $this;
	
	}

	public function eof() {
		return $this->EOF;

	}

	public function __destruct() {
		Daemon::log('destructor');
	}

	public function onEOF($cb = NULL) {
		$this->onEOF = CallbackWrapper::wrap($cb);

		return $this;
	}
}
