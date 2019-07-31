#!/usr/bin/php
<?php /** @noinspection ALL */
#########################################################################################################
#
# AdorableIllusion Backup Manager
#
# (C) 2019 AdorableIllusion <code@adorable-illusion.com>
#
#########################################################################################################

$backup = new AdorableIllusion_BackupRestore($argv); # ($argv, true) für debugging
$backup->execute();
exit();

/* 
	Prio 2: 
		+ Clean up code (move methods to their proper places, use includes, remove spurious variables etc.

	Prio 4:
		+ bug - if FROM or TO are buckets, it always makes TO = FROM
*/
		
#########################################################################################################
# Classes
#########################################################################################################

class AdorableIllusion_BackupRestore {

	#########################################################################################################
	# Config
	#########################################################################################################
	public $name 							= "AdorableIllusion Backup Manager";
	public $version 						= "3.0.6";
	public $exclude_patterns 				= array('\#snapshot/**', '@eaDir/**', 'Downloads_Torrents/**', '*.part', '_tmp/**', '.svn/**', '.git/**');
	public $include_patterns 				= array();
	public $dry_run 						= false;
	public $debug							= false;
	public $verbose							= false;

	const TYPE_BACKUP						= "backup";
	const TYPE_MOVE							= "move";
	const TYPE_RESTORE						= "restore";
	const TYPE_UNRAR						= "unrar";
	const TYPE_CLOUD						= "cloud";
	const TYPE_LOCAL						= "local";

	const TYPES								= array(self::TYPE_BACKUP, self::TYPE_MOVE, self::TYPE_RESTORE, self::TYPE_UNRAR, self::TYPE_CLOUD, self::TYPE_LOCAL);
	
	const SYNCTYPE_RCLONE_SYNC				= "sync";
	const SYNCTYPE_RCLONE_COPY				= "copy";
	const SYNCTYPE_RSYNC_SYNC				= ""; # maybe set to "--delete" later
	const SYNCTYPE_RSYNC_COPY				= "";
	
	const FIELD_BACKUP_TARGETS				= "backup_targets";
	const FIELD_delete						= "delete";
	const FIELD_EXCLUDES					= "excludes";
	const FIELD_INCLUDES					= "includes";
	const FIELD_NAME						= "name";
	const FIELD_PATH						= "path";
	const FIELD_SOURCES						= "sources";
	const FIELD_TARGET						= "target";
	const FIELD_TYPE 						= "type";
	
	const ARGUMENT_DRYRUN					= "--dry-run";
	
	/* Generic variables */
	public $configFile 						= '/volume1/service/sync/config-backup-and-restore.yml';
    public $targetsToRun 					= array();
    public $configData 						= array();
    public $arguments 						= array();
    public $options 						= array();
	
	public $default_commandline 			= "#DRYRUN# #CONVERT# #delete# #UPDATE# #EXCLUDE# #INCLUDE# #FROM# #TO#";
	public $move_default_command			= "mv ";
													/* Need "-O --no-t --no-perms --chmod=Du+rwx" to work for non-root users on CIFS mounts */
	public $rsync_default_command 			= "rsync #SYNCMODE# -avhO --no-t --no-perms --chmod=Du+rwx --progress --delete --update ";
	public $rclone_default_command 			= "rclone #SYNCMODE# --progress --fast-list --transfers 24 ";
	public $unrar_default_command			= "unrar-downloads.sh ";
	public $from_replacements 				= array(
													self::TYPE_LOCAL => array('/volume1/' => '/volume1/NetBackup/'),
													self::TYPE_CLOUD => array(),
													self::TYPE_UNRAR => array(),
													self::TYPE_MOVE => array(),
												);
	public $default_syncmode				= array(self::TYPE_LOCAL => '', 				self::TYPE_CLOUD => 'sync', 	self::TYPE_UNRAR => '', self::TYPE_MOVE => '',);	
	public $include_exclude_delim			= array(self::TYPE_LOCAL => '\'', 				self::TYPE_CLOUD => '',			self::TYPE_UNRAR => '', self::TYPE_MOVE => '',);
	public $include_exclude_replacements 	= array(self::TYPE_LOCAL => array('**' => '*'),	self::TYPE_CLOUD => array(),	self::TYPE_UNRAR => '', self::TYPE_MOVE => '',);
	
	/* Variables for targets */
	public $defaultCommands					= array();
	public $includes 						= array();
	public $excludes 						= array();
	public $syncmode						= array();
	public $from							= array();
	public $to								= array();
	public $runtime							= array();

	/* Variables for console input */
	public $TARGET_CONSOLE 					= "";
    public $FROM_CONSOLE 					= "";
    public $TO_CONSOLE 						= "";

	/* Global flags */
	public $DRYRUN 							= "";
	public $convert 						= "";
	public $delete 							= "";
	public $update 							= "";

	/**
	* __construct
	*
	*
	*/
	public function __construct($commandLine, $debug = false) {
		$this->debug = $debug;
		$this->parseInput($commandLine);
		return true;
	}
	
	/**
	* execute
	*
	*
	*/
	public function execute () {
		passthru("clear");
		$this->calculateRuntimes("");			
		$this->printBanner("");				
		foreach ($this->targetsToRun as $targetName) {
			$this->runTarget($targetName);
		}
		return true;
	}
	
	/**
	* parseInput
	*
	*
	*/
	public function parseInput($commandLine) {
		$this->parseConfigFile(false);
		$this->parseCommandLine($commandLine);
		$this->parseArguments();
		$this->parseOptions();
		$this->parseConfigFile(); # unfortunately we need this twice right now...
		return true;
	}
	
	/**
	* runTarget
	*
	*
	*/
	public function runTarget($targetName) {
		$this->calculateRuntimes($targetName);			
		$this->printBanner($targetName);				
		$subTargets = $this->getTargetField($targetName, self::FIELD_SOURCES);
		if (is_array($subTargets) && count($subTargets) >= 1) {
			foreach ($subTargets as $subtarget) {
				$this->runSubTarget($targetName, $subtarget);
			}
		}
		return true;
	}
	
	/**
	* runSubTarget
	*
	*
	*/
	public function runSubTarget($targetName, $subtarget) {
		$this->generateSyncMode($targetName, $subtarget);
		$this->generateIncludesAndExcludes($targetName, $subtarget);
		$this->setFromAndTo($targetName, $subtarget);				
		$this->calculateRuntimes($targetName, $subtarget);			
		$this->printBanner($targetName, $subtarget);				
		$this->runCommand($targetName, $subtarget);					
		return true;
	}

	/**
	* calculateRuntimes
	*
	*
	*/
	public function calculateRuntimes($targetName, $subtarget = array()) {
		if ($targetName == "") {
			$runtime_total_initial = 0;
			$runtime_total_update = 0;
			foreach ($this->targetsToRun as $targetName) {
				$this->calculateRuntimes($targetName);
				$runtime_total_initial += $this->getRuntime($targetName, array(), 'initial', false);
				$runtime_total_update += $this->getRuntime($targetName, array(), 'update', false);
			}
			$this->setRuntime("--ALLTARGETS--", array(), 'initial', $runtime_total_initial);
			$this->setRuntime("--ALLTARGETS--", array(), 'update', $runtime_total_update);
		}
		elseif ($subtarget == array()) {
			$runtime_total_initial = 0;
			$runtime_total_update = 0;
			$subTargets = $this->getTargetField($targetName, self::FIELD_SOURCES);
			if (is_array($subTargets) && count($subTargets) >= 1) {
				foreach ($subTargets as $item) {
					$runtime_initial = (isset($item['runtime_initial'])?$item['runtime_initial']:0);
					$runtime_update = (isset($item['runtime_update'])?$item['runtime_update']:0);
					$runtime_total_initial += (int) $runtime_initial;
					$runtime_total_update += (int) $runtime_update;
				}
			}
			$this->setRuntime($targetName, $subtarget, 'initial', $runtime_total_initial);
			$this->setRuntime($targetName, $subtarget, 'update', $runtime_total_update);
		} else {
				$runtime_initial = (isset($subtarget['runtime_initial'])?$subtarget['runtime_initial']:0);
				$runtime_update = (isset($subtarget['runtime_update'])?$subtarget['runtime_update']:0);
				$this->setRuntime($targetName, $subtarget, 'initial', $runtime_initial);
				$this->setRuntime($targetName, $subtarget, 'update', $runtime_update);
		}
	}
	
	/**
	* getRuntime
	*
	*
	*/
	public function getRuntime($targetName, $subtarget, $type, $padded = true) {
		if ($subtarget == array()) {
			$runtime = $this->runtime[$targetName][$this->getIdentifier($targetName . '::' . $type)][$type];
		} else {
			$runtime = $this->runtime[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])][$type];
		}
		if ($padded) {
			return $this->getPaddedRuntime($runtime);
		} else {
			return $runtime;
		}
	}

	/**
	* setRuntime
	*
	*
	*/
	public function setRuntime($targetName, $subtarget, $type, $runtime) {
		if ($subtarget == array()) {
			$this->runtime[$targetName][$this->getIdentifier($targetName . '::' . $type)][$type] = $runtime;
		} else {
			$this->runtime[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])][$type] = $runtime;
		}
	}
	
	/**
	* printBanner
	*
	*
	*/
	public function printBanner($targetName, $subtarget = array()) {
		if ($targetName == "") {
		echo "
*********************************************************************************************************
* " . $this->name . " " . $this->version . "
*
* (C) 2019 Adorable Illusion <code@adorable-illusion.com>
*
* Mode   : " . $this->MODE . "
* Targets: " . $this->TARGET_CONSOLE . "
* Runtime: " . $this->getRuntime("--ALLTARGETS--", array(), 'initial') . " (initial run)
*        : " . $this->getRuntime("--ALLTARGETS--", array(), 'update') . " (update)
*********************************************************************************************************
";		
		if (!$this->debug) {
			#sleep(5);
		}
	} else if ($subtarget == array() && $this->verbose) {		
		echo "
*********************************************************************************************************
* Target     : $targetName
* Description: " . $this->getTargetField($targetName, 'description') . "
* Runtime    : " . $this->getRuntime($targetName, array(), 'initial') . " (initial run)
*            : " . $this->getRuntime($targetName, array(), 'update') . " (update)
*********************************************************************************************************
";		
		if (!$this->debug) sleep(1);
		} else if ($this->verbose) {
	echo "
*********************************************************************************************************
* Description: " . (isset($subtarget['description'])?$subtarget['description']:'---') . " 
* From       : " . $this->getFrom($targetName, $subtarget) . "
* To         : " . $this->getTo($targetName, $subtarget) . "
* Runtime    : " . $this->getRuntime($targetName, $subtarget, 'initial') . " (initial run)
*            : " . $this->getRuntime($targetName, $subtarget, 'update') . " (update)
*********************************************************************************************************
";			
		}
		
	}
	
	/**
	* setFromAndTo
	*
	*
	*/
	public function setFromAndTo($targetName, $subtarget) {

		/* Get FROM and TO from subtarget, else target, else from */

		$this->setFrom($targetName, $subtarget, $subtarget['path']);
		if (isset($subtarget['target'])) {
			$this->setTo($targetName, $subtarget, $subtarget['target']);
		} elseif ($this->getTargetField($targetName, self::FIELD_TARGET)) {
			$this->setTo($targetName, 
							$subtarget, 
							$this->autoCompleteTo($targetName, $subtarget, $this->getTargetField($targetName, self::FIELD_TARGET)));
		}
		else {
			$this->setTo($targetName, $subtarget, $this->autoCompleteTo($targetName, $subtarget, $this->getFrom($targetName, $subtarget)));
		}		
	
		/* If we are restoring, swap FROM and TO unless we've come from console */
		
		if ($this->MODE == self::TYPE_RESTORE && $targetName != 'console') {
			$from = $this->getFrom($targetName, $subtarget);
			$to = $this->getTo($targetName, $subtarget);
			$this->setFrom($targetName, $subtarget, $to);
			$this->setTo($targetName, $subtarget, $from);
		}

		/* Command line overrides:  if have a target != console and one parameter, means user wants to change TO */
		if ($targetName != 'console' && isset($this->arguments[1])) {
			$this->setTo($targetName, $subtarget, $this->autoCompleteTo($targetName, $subtarget, $this->arguments[1]));
		}
		
	}
	
	/**
	* autoCompleteTo
	*
	*
	*/
	public function autoCompleteTo($targetName, $subtarget, $to) {
		/* If TO is just a bucket name, append FROM */
		$from = $this->getFrom($targetName, $subtarget);
		if (substr($to, strlen($to)-1, 1) == ':') {
			$to .= $from;
			$this->setTo($targetName, $subtarget, $to);
			return $to;
		} 
	
		/* If TO came from the command line with a target, do not replace anything */
		if ($targetName != 'console' && isset($this->arguments[1])) {
			return str_replace(' ', '\\ ', $to);
		}
		
		/* If TO came from the command line with a FROM, make sure it is kept */
		if ($targetName == 'console' && isset($this->arguments[2])) {
			return str_replace(' ', '\\ ', $this->arguments[2]);
		}
		

		/* If "to" is not a bucket, apply replacements depending on sync type */
		foreach ($this->from_replacements[$this->getTargetType($targetName)] as $from_from => $from_to) {
			$to = str_ireplace($from_from, $from_to, $to);
		}
		return $to;
	}
	
	
	/**
	* parseCommandLine
	*
	*
	*/
	public function parseCommandLine($data) {
		$this->options = array();
		$this->arguments = array();
		foreach ($data as $index => $argument)
		{
			if ($index == 0) continue;
			if (strstr($argument, '--') !== false) {
				$argument = substr($argument, 2, strlen($argument) - 2);
				$value = null;
				if (strstr($argument, '=') !== false) {
					$tmp = explode('=', $argument);
					$argument = $tmp[0];
					$value = $tmp[1];
				}
				$this->options[$argument] = $value;
			} else {
				$this->arguments[] = $argument;
			}
		}
		return true;
	}
	
	/**
	* parseArguments
	*
	*
	*/
	public function parseArguments() {
		$this->MODE = (isset($this->arguments[0])?$this->arguments[0]:'none');
		if ($this->MODE == 'none') $this->options['help'] = true;
		$this->FROM_CONSOLE = (isset($this->arguments[1])?$this->arguments[1]:'');
		$this->TO_CONSOLE = (isset($this->arguments[2])?$this->arguments[2]:"");
		return true;
	}
	
	/**
	* parseOptions
	*
	*
	*/
	public function parseOptions() {
		foreach ($this->options as $option => $value)
		{
				switch ($option) {
                    case 'help':
					case 'h':
                        $this->displayHelp();
                        exit();
                        break;
					case 'target':
					case 't':
						$this->TARGET_CONSOLE = strtolower($value);
						break;
					case 'dry-run':
						$this->dry_run = true;
						break;
					case 'debug':
						$this->debug = true;
						$this->dry_run = true;
					case 'verbose':
					case 'v':
						$this->verbose = true;
						break;
					case 'show-targets':	
						$this->showTargets();
						exit();
						break;
					case 'list-targets':	
					case 'show-details':
						echo "Warning: not implemented option <$option>" . chr(13) . chr(10);
						die();
						break;					
					default:
						echo "Warning: unknown option <$option>" . chr(13) . chr(10);
						die();
						break;
				}
		}		
	}

	/**
	* showTargets
	*
	*
	*/
	public function showTargets() {
		$targets = array();
		$filter = (isset($this->arguments[0])?$this->arguments[0]:"");
		foreach ($this->configData['targets'] as $targetName => $data) {
			if ($filter == "" || strstr($targetName, $filter) !== false) {
				$targets[$targetName] = $data;
			}
		}
		ksort($targets);
		foreach ($targets as $targetName => $data) {
			$target_level = count(explode('-', $targetName));	
			switch ($target_level)
			{
				case 1:
					$spacer = "";
					break;
				case 2:
					$spacer = "  ";
					break;
				case 3:
					$spacer = "    ";
					break;
				case 4:
					$spacer = "      ";
					break;
				case 5:
					$spacer = "        ";
					break;
				default:
					break;
			}
			echo $spacer . $targetName . chr(13) . chr(10);
		}
	}

	/**
	* displayHelp
	*
	*
	*/
	public function displayHelp() {
		$tmp = explode('/', $_SERVER['argv'][0]);
		$scriptName = $tmp[count($tmp)-1];
        echo "
" . $this->name  . " " . $this->version . "
*************************************************************************************************************
* Backup.
*************************************************************************************************************

 Backup predefined target(s)
 	$scriptName backup --target=photos
 	$scriptName backup --target=cloud-core
 	$scriptName backup --target=photos,cloud-core,annette
 Backup location to default target
	$scriptName backup /volume1/foo
 Backup location to different directory/bucket
	$scriptName backup /volume1/foo /somewhere
	$scriptName backup /volume1/foo b2-core:
	$scriptName backup /volume1/foo b2-core:/other/dir

*************************************************************************************************************
* Restore.
*************************************************************************************************************

 Restore predefined target(s)
 	$scriptName restore --target=photos
 	$scriptName restore --target=cloud-core
 	$scriptName restore --target=photos,cloud-core,annette
 Restore predefined target to different directory
 	$scriptName restore --target=adorable-illusion /tmp/restore
 Restore location from default target
	$scriptName restore /volume1/foo
	$scriptName restore /volume1/NetBackup/foo	[same as previous]
	$scriptName restore b2-core:
	$scriptName restore b2-core:/volume1/somedir
 Restore location from default target elsewhere
	$scriptName restore /volume1/foo /elsewhere
	$scriptName restore /volume1/NetBackup/foo /elsewhere	[same as previous]
	$scriptName restore b2-core: /elsewhere
	$scriptName restore b2-core:/somedir /elsewhere

*************************************************************************************************************
* Options.
*************************************************************************************************************

    --target=<target(s)>         Comma-separated list of targets.

    --debug                      Show debug output. Implies --dry-run.
    --dry-run                    Only test commands.
    --exclude=<pattern>          Exclude dirs/files by pattern.
    --help                       Print this help.
    --include=<pattern>          Include dirs/files by pattern.
    --show-targets <filter>      Show all available targets matching <filter>.
    --verbose                    Print more banners.

    --list-targets               List all targets with details. [not implemented]
    --show-details               Show details for current target(s). [not implemented]
   
        
";
    }

	/**
	* parseConfigFile
	*
	*
	*/
	public function parseConfigFile($setConsoleTargetIfRequired = true) {
		$config = yaml_parse_file($this->configFile);
		
		$config = $this->resolveConfigVariables($config, $config);

		$this->configData = $config;
		
		/* Read comma-separated list of targets from console */
		if ($this->TARGET_CONSOLE != '') {
			$targets = explode(',', $this->TARGET_CONSOLE);
			foreach ($targets as $targetName) {
				$this->targetsToRun[] = $targetName;
			}
		}
		
		/* Resolve targets calling other targets */
		for ($i = 1; $i <= 10; $i++) { # at most 10 levels deep
			foreach ($this->targetsToRun as $index => $targetName) {
				if (isset($this->configData['targets'][$targetName][self::FIELD_BACKUP_TARGETS])) {
					$this->targetsToRun = array_merge($this->targetsToRun, $this->configData['targets'][$targetName][self::FIELD_BACKUP_TARGETS]);
					$this->targetsToRun = array_unique($this->targetsToRun);
				}
			}
		}
		
		/* If no target defined, we are running from console input */
		if ($this->TARGET_CONSOLE == "" && $setConsoleTargetIfRequired) {
			$this->configData['targets']['console'] = array(
				self::FIELD_NAME 		=> 'console',
				self::FIELD_TYPE 		=> ((strstr($this->FROM_CONSOLE . $this->TO_CONSOLE, ':') !== false)?(self::TYPE_CLOUD):(self::TYPE_LOCAL)),
				self::FIELD_SOURCES 	=> array(array(self::FIELD_PATH => $this->FROM_CONSOLE)),
			);
			$this->targetsToRun[] = 'console';
		}
		asort($this->targetsToRun);
		return true;
	}
	
	/**
	* resolveConfigVariables
	*
	*
	*/
	public function resolveConfigVariables($top_source, $source) {
		if (is_array($source)) {
			foreach ($source as $index => $item) {
				$source[$index] = $this->resolveConfigVariables($top_source, $item);
			}
		} else {
			// resolve "@a.b.c" to "$top_source['a']['b']['c']"
			if (substr($source, 0, 1) == "@") {
				$source = substr($source, 1, strlen($source) - 1);
				$keys = explode('.', $source);
				$result = $top_source;
				foreach ($keys as $key){
					$result = $result[$key];
				}
				$source = $result;
			} 
		}
		return $source;
	}

	/**
	* getIdentifier
	*
	*
	*/
	protected function getIdentifier($string) {
		return $string;
	}

	/**
	* generateIncludesAndExcludes
	*
	*
	*/
	protected function generateIncludesAndExcludes($targetName, $subtarget) {
		$this->setIncludes($targetName, $subtarget, $this->generateInExcludeData($targetName, $subtarget, 'include'));
		$this->setExcludes($targetName, $subtarget, $this->generateInExcludeData($targetName, $subtarget, 'exclude'));
		return true;
	}
	
	/** 
	* isType
	*
	*
	*/
	
	protected function isType($type) {
		/* "backup" is matched by "b", "ba", ... */
		foreach (self::TYPES as $definedType) {
			if ($this->MODE == $definedType && substr($definedType, 0, strlen($type)) == $type) {
				return true;
			}
		}
		return false;
	}

	/**
	* getFrom
	*
	*
	*/
	protected function getFrom($targetName, $subtarget) {
		return $this->from[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])];
	}
	/**
	* setFrom
	*
	*
	*/
	protected function setFrom($targetName, $subtarget, $from) {
		if (strstr($from, '/') !== false && substr($from, strlen($from)-1, 1) != '/') {
			$from .= '/';
		}			
		$this->from[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])] = $from;
	}

	/**
	* getTo
	*
	*
	*/
	protected function getTo($targetName, $subtarget) {
		return $this->to[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])];
	}
	/**
	* setTo
	*
	*
	*/
	protected function setTo($targetName, $subtarget, $to) {
		if (strstr($to, '/') !== false && substr($to, strlen($to)-1, 1) != '/') {
			$to .= '/';
		}			
		$this->to[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])] = $to;
	}

	/**
	* getSyncMode
	*
	*
	*/
	protected function getSyncMode($targetName, $subtarget) {
		return $this->syncmode[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])];
	}

	/**
	* setSyncMode
	*
	*
	*/
	protected function setSyncMode($targetName, $subtarget, $syncMode) {
		$this->syncmode[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])] = $syncMode;
	}

	
	/**
	* getExcludes
	*
	*
	*/
	protected function getExcludes($targetName, $subtarget) {
		return $this->excludes[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])];
	}

	/**
	* getIncludes
	*
	*
	*/
	protected function getIncludes($targetName, $subtarget) {
		return $this->includes[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])];
	}

	/**
	* setExcludes
	*
	*
	*/
	protected function setExcludes($targetName, $subtarget, $excludes) {
		$this->excludes[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])] = $excludes;
	}

	/**
	* setIncludes
	*
	*
	*/
	protected function setIncludes($targetName, $subtarget, $includes) {
		$this->includes[$targetName][$this->getIdentifier($targetName . '::' . $subtarget[self::FIELD_PATH])] = $includes;
	}
	
	/**
	* generateSyncMode
	*
	*
	*/
	protected function generateSyncMode($targetName, $subtarget) {
		/* Set default sync mode */
		$syncType = $this->getTargetType($targetName);
		switch ($syncType) {
			case self::TYPE_LOCAL:
				$syncModeSync = self::SYNCTYPE_RSYNC_SYNC;
				$syncModeCopy = self::SYNCTYPE_RSYNC_COPY;
				break;
			case self::TYPE_CLOUD:
				$syncModeSync = self::SYNCTYPE_RCLONE_SYNC;
				$syncModeCopy = self::SYNCTYPE_RCLONE_COPY;
				break;
			default:
				$syncModeSync = '';
				$syncModeCopy = '';
				break;
		}
		$syncMode = $syncModeSync;
		/* Override from target */
		$syncMode = (($this->getTargetField($targetName, self::FIELD_delete) === false || $this->isType(self::TYPE_RESTORE))?$syncModeCopy:$syncMode);
		/* Override from subtarget */
		$syncMode = (isset($subtarget[self::FIELD_delete]) && ($subtarget[self::FIELD_delete] === false || $this->isType(self::TYPE_RESTORE))?$syncModeCopy:$syncMode);
		$this->setSyncMode($targetName, $subtarget, $syncMode);
	}

	/**
	* generateInExcludeData
	*
	*
	*/
	protected function generateInExcludeData($targetName, $subtarget, $mode) {
		$patterns = array();
		if (isset($subtarget[$mode . 's'])) {
			$patterns = $subtarget[$mode . 's'];
		}
		if ($mode == 'exclude') {
			$patterns = array_merge($this->exclude_patterns, $patterns);
		}
		$data = array();
		$delim = $this->include_exclude_delim[$this->getTargetType($targetName)];
		$replacements = $this->include_exclude_replacements[$this->getTargetType($targetName)];
		if (is_array($patterns) && count($patterns) > 0 ) {
			foreach ($patterns as $pattern) {
				if (is_array($replacements) && count($replacements) > 0) {
					foreach ($replacements as $from => $to) {
						$pattern = str_replace($from, $to, $pattern);
					}
				}
				$data[] = '--' . $mode . ' ' . $delim . $pattern . $delim;
			}
		}
		return implode(' ', $data);
	}
	
	/**
	* getTargetField
	*
	*
	*/
	protected function getTargetField($targetName, $fieldName) {
		if (!isset($this->configData['targets'][$targetName][$fieldName])) {
			return null;
		}
		return $this->configData['targets'][$targetName][$fieldName];
	}
	
	/**
	* getTargetType
	*
	*
	*/
	protected function getTargetType($targetName) {
		return $this->getTargetField($targetName, self::FIELD_TYPE);
	}
	
	/**
	* replacePlaceholders
	*
	*
	*/
	protected function replacePlaceholders($text, $targetName, $subtarget) {
		$delete = (isset($subtarget['delete'])?$subtarget['delete']:$this->getTargetField($targetName, 'delete'));
		$replacements = array(
			'#SYNCMODE#' => $this->getSyncMode($targetName, $subtarget),
			'#DRYRUN#' => ($this->dry_run?(self::ARGUMENT_DRYRUN):''),
			'#CONVERT#' => $this->convert,
			'#delete#' => ($delete?(self::ARGUMENT_delete):''),
			'#UPDATE#' => $this->update,
			'#EXCLUDE#' => $this->getExcludes($targetName, $subtarget),
			'#INCLUDE#' => $this->getIncludes($targetName, $subtarget),
			'#FROM#' => $this->getFrom($targetName, $subtarget),
			'#TO#' => $this->getTo($targetName, $subtarget), 
		);
		foreach ($replacements as $key => $value) {
			$text = str_replace($key, $value, $text);
		}
		return $text;
	}

	/**
	* getPaddedRuntime
	*
	*
	*/
	protected function getPaddedRuntime($seconds) {
		$hours = floor($seconds/3600);
		$seconds -= $hours*3600;
		$minutes = floor($seconds/60);
		$seconds -= $minutes*60;
		if ($hours < 10) $hours = "0" . $hours;
		if ($minutes < 10) $minutes = "0" . $minutes;
		if ($seconds < 10) $seconds = "0" . $seconds;
		return $hours . ":" . $minutes . ":" . $seconds;
	}
	
	/**
	* runCommand
	*
	*
	*/
	public function runCommand($targetName, $subtarget) {
		$backupType = $this->getTargetType($targetName);
		switch($backupType) {
			case self::TYPE_LOCAL:
				$command = $this->rsync_default_command . " " . $this->default_commandline;
				break;
			case self::TYPE_CLOUD:
				$command = $this->rclone_default_command . " " . $this->default_commandline;
				break;
			case self::TYPE_UNRAR:
				$command = $this->unrar_default_command;
				break;			
			case self::TYPE_MOVE:
				$command = "cd #FROM#; " . $this->move_default_command  . " #FILE# #TO#";
				break;			
			default:
				die("ERROR: runCommand(): no backup type found for target $targetName!");
				break;
		}
		$command = $this->replacePlaceholders($command, $targetName, $subtarget);
		
		if ($this->debug) echo "Run command $command";

		/* Must turn off globbing or some "*" will be expanded. *sigh* */
		passthru("set -f; " . $command);
		
	}
	
}