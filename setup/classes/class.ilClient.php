<?php
/*
	+-----------------------------------------------------------------------------+
	| ILIAS open source                                                           |
	+-----------------------------------------------------------------------------+
	| Copyright (c) 1998-2001 ILIAS open source, University of Cologne            |
	|                                                                             |
	| This program is free software; you can redistribute it and/or               |
	| modify it under the terms of the GNU General Public License                 |
	| as published by the Free Software Foundation; either version 2              |
	| of the License, or (at your option) any later version.                      |
	|                                                                             |
	| This program is distributed in the hope that it will be useful,             |
	| but WITHOUT ANY WARRANTY; without even the implied warranty of              |
	| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the               |
	| GNU General Public License for more details.                                |
	|                                                                             |
	| You should have received a copy of the GNU General Public License           |
	| along with this program; if not, write to the Free Software                 |
	| Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA. |
	+-----------------------------------------------------------------------------+
*/

/**
* client management
*
* @author Sascha Hofmann <shofmann@databay.de> 
* @version $Id$
*
* @package ilias-setup
*/

class ilClient
{
	var $id;					// client_id (md5 hash)
	var $dir;					// directory name in ilias/clients/
	var $name;					// installation name
	var $db_exists = false;		// db exists?
	var $db_installed = false;	// db installed?

	var $client_defaults;	// default settings
	var $status;			// contains status infos about setup process (todo: move function to this class)
	var $setup_ok = false;	// if client setup was finished at least once, this is set to true

	// Constructor
	function ilClient($a_client_id = 0)
	{
		if ($a_client_id)
		{
			$this->id = $a_client_id;
			$this->ini_file_path = ILIAS_ABSOLUTE_PATH."/".ILIAS_WEB_DIR."/".$this->getId()."/client.ini.php";
		}

		// set path default.ini
		$this->client_defaults = ILIAS_ABSOLUTE_PATH."/setup/client.master.ini.php";
	}
	
	function init()
	{
		$this->ini = new ilIniFile($this->ini_file_path);
	
		// load defaults only if no client.ini was found
		if (!@file_exists($this->ini_file_path))
		{
			$this->ini->GROUPS = parse_ini_file($this->client_defaults,true);
			//$this->ini->setVariable("server","data_dir",ILIAS_DATA_DIR."/".$this->getId());
			return false;
		}

		// read client.ini
		if (!$this->ini->read())
		{
			$this->error = get_class($this).": ".$this->ini->getError();
			return false;		
		}

		// only for ilias main
		define(CLIENT_WEB_DIR,ILIAS_ABSOLUTE_PATH."/".ILIAS_WEB_DIR."/".$this->getId());
		define(CLIENT_DATA_DIR,ILIAS_DATA_DIR."/".$this->getId());
		
		$this->db_exists = $this->connect();
		
		if ($this->db_exists)
		{
			$this->db_installed = $this->isInstalledDB($this->db);
		}
		
		return true;	
	}
	
	function getId()
	{
		return $this->id;
	}
	
	function setId($a_client_id)
	{
		$this->id = $a_client_id;
		$this->webspace_dir = ILIAS_ABSOLUTE_PATH."/".ILIAS_WEB_DIR."/".$this->id;
	}
	
	function getName()
	{
		return $this->ini->readVariable("client","name");
	}
	
	function setName($a_str)
	{
		$this->ini->setVariable("client","name",$a_str);
	}
	
	function getDescription()
	{
		return $this->ini->readVariable("client","description");
	}
	
	function setDescription($a_str)
	{
		$this->ini->setVariable("client","description",$a_str);
	}

	/**
	* connect
	*/
	function connect()
	{
		// check parameters
		if (!$this->getdbHost() || !$this->getdbName() || !$this->getdbUser())
		{
			$this->error = "empty_fields";
			return false;
		}

		$this->setDSN();

		$this->db = DB::connect($this->dsn,true);
		
		if (DB::isError($this->db))
		{
			$this->error = $this->db->getMessage()."! not_connected_to_db";
			return false;
		}
		
		$this->db_exists = true;
		return true;
	}

	/**
	* installed db?
	*/
	function isInstalledDB(&$a_db)
	{
		$q = "SHOW TABLES";
		$r = $a_db->query($q);
		
		$tables = array();

		while ($row = $r->fetchRow(DB_FETCHMODE_ASSOC))
		{
			$tables[] = implode($row);
		}

		if (in_array("object_data",$tables))
		{
			$this->db_installed = true;
			return true;
		}
		
		$this->db_installed = false;
		return false;
	}

	/**
	* set the dsns
	*/
	function setDSN()
	{

		$this->dsn_host = "mysql://".$this->getdbUser().":".$this->getdbPass()."@".$this->getdbHost();
		$this->dsn = "mysql://".$this->getdbUser().":".$this->getdbPass()."@".$this->getdbHost()."/".$this->getdbName();
	}

	/**
	* set the host
	* @param	string
	*/
	function setDbHost($a_str)
	{
		$this->ini->setVariable("db","host",$a_str);
	}
	
	function getDbHost()
	{
		return $this->ini->readVariable("db","host");
	}

	/**
	* set the name of database
	* @param	string
	*/
	function setDbName($a_str)
	{
		$this->ini->setVariable("db","name",$a_str);
	}

	/**
	* get name of database
	* @return		string		name of database
	*/
	function getDbName()
	{
		return $this->ini->readVariable("db","name");
	}

	/**
	* set the user
	* @param	string
	*/
	function setDbUser($a_str)
	{
		$this->ini->setVariable("db","user",$a_str);
	}
	
	function getDbUser()
	{
		return $this->ini->readVariable("db","user");
	}

	/**
	* set the password
	* @param	string
	*/
	function setDbPass($a_str)
	{
		$this->ini->setVariable("db","pass",$a_str);
	}
	
	function getDbPass()
	{
		return $this->ini->readVariable("db","pass");
	}

	function getDataDir()
	{
		return ILIAS_DATA_DIR."/".$this->getId();
	}

	function getWebspaceDir()
	{
		return ILIAS_ABSOLUTE_PATH."/".ILIAS_WEB_DIR."/".$this->getId();
	}

	/**
	* check database connection
	* @return	boolean
	*/
	function checkDatabaseHost()
	{
		//connect to databasehost
		$db = DB::connect($this->dsn_host);

		if (DB::isError($db))
		{
			$this->error = $db->getMessage()."! Please check database hostname, username & password.";
			return false;
		}
		
		return true;
	}

	/**
	* check database connection
	* @return	array
	*/
	function checkDatabaseExists()
	{
		//try to connect to database
		$db = DB::connect($this->dsn);

		if (DB::isError($db))
		{
			return false;
		}
		
		if (!$this->isInstalledDB($db))
		{
			return false;
		}

		return true;
	}

	/**
	* read one value from settings table
	* @access	public
	* @param	string	keyword
	* @return	string	value
	*/
	function getSetting($a_keyword)
	{
		$q = "SELECT value FROM settings WHERE keyword='".$a_keyword."'";
		$r = $this->db->query($q);

		if ($r->numRows() > 0)
		{
			$row = $r->fetchRow();
			return $row[0];
		}
		else
		{
			return false;
		}
	}

	/**
	* read all values from settings table
	* @access	public
	* @return	array	keyword/value pairs
	*/
	function getAllSettings()
	{
		$q = "SELECT * FROM settings";
		$r = $this->db->query($q);

		while ($row = $r->fetchRow(DB_FETCHMODE_ASSOC))
		{
			$arr[$row["keyword"]] = $row["value"];
		}
		
		return $arr;
	}

	/**
	* write one value to settings table
	* @access	public
	* @param	string		keyword
	* @param	string		value
	* @return	boolean		true on success
	*/
	function setSetting($a_key, $a_val)
	{
		$q = "REPLACE INTO settings SET keyword = '".$a_key."', value = '".$a_val."'";
		$this->db->query($q);
		
		//$q = "DELETE FROM settings WHERE keyword='".$a_key."'";
		//$r = $this->db->query($q);

		//$q = "INSERT INTO settings (keyword, value) VALUES ('".$a_key."','".$a_val."')";
		//$r = $this->db->query($q);

		return true;
	}
	
	function getURLStringForNIC($a_nic_url)
	{
		$settings = $this->getAllSettings();

		$inst_id = (empty($settings["inst_id"])) ? "0" : $settings["inst_id"];

		// send host information to ilias-nic
		$url = 	$a_nic_url.
				"?cmd=getid".
				"&inst_id=".rawurlencode($inst_id).
				"&hostname=".rawurlencode($_SERVER["SERVER_NAME"]).
				"&ipadr=".rawurlencode($_SERVER["SERVER_ADDR"]).
				"&server_port=".rawurlencode($_SERVER["SERVER_PORT"]).
				"&server_software=".rawurlencode($_SERVER["SERVER_SOFTWARE"]).
				"&inst_name=".rawurlencode($this->ini->readVariable("client","name")).
				"&inst_info=".rawurlencode($this->ini->readVariable("client","description")).
				"&institution=".rawurlencode($settings["inst_institution"]).
				"&http_path=".rawurlencode(ILIAS_HTTP_PATH).
				"&contact_firstname=".rawurlencode($settings["admin_firstname"]).
				"&contact_lastname=".rawurlencode($settings["admin_lastname"]).
				"&contact_title=".rawurlencode($settings["admin_title"]).
				"&contact_position=".rawurlencode($settings["admin_position"]).			
				"&contact_institution=".rawurlencode($settings["admin_institution"]).
				"&contact_street=".rawurlencode($settings["admin_street"]).
				"&contact_pcode=".rawurlencode($settings["admin_zipcode"]).
				"&contact_city=".rawurlencode($settings["admin_city"]).
				"&contact_country=".rawurlencode($settings["admin_country"]).
				"&contact_phone=".rawurlencode($settings["admin_phone"]).
				"&contact_email=".rawurlencode($settings["admin_email"]).
				"&nic_key=".rawurlencode($this->getNICkey()).
				"&version=".rawurlencode($settings["ilias_version"]);

		return $url;
	}
	
	/**
	* Connect to ILIAS-NIC
	*
	* This function establishes a HTTP connection to the ILIAS Network
	* Information Center (NIC) in order to update the ILIAS-NIC host
	* database and - in case of a newly installed system - obtain an
	* installation id at first connection. 
	* This function my be put into a dedicated include file as soon
	* as there are more functions concerning the interconnection of
	* ILIAS hosts
	*
	* @param	void 
	* @return	string/array	$ret	error message or data array
	*/
	function updateNIC($a_nic_url)
	{
		$url = $this->getURLStringForNIC($a_nic_url);

		$conn =fopen($url,"r");
		
		$input = "";
	
		if (!$conn) 
		{
			return false;
		}
		else
		{
			while(!feof($conn))
			{
				$input.= fgets($conn, 4096);
			}

			fclose($conn);
			$line = explode("\n",$input);
			
			$ret = $line;
		}

		$this->nic_status = $ret;

		return true;
	}
	
	/**
	* set nic_key
	* generate nic_key if nic_key field in cust table is empty.
	* the nic_key is used for authentication update requests sent
	* to the ILIAS-NIC server.
	* @access	public
	* @return	boolean
	*/
	function setNICkey()
	{
		mt_srand((double)microtime()*1000000);
		$nic_key =	md5(str_replace(".","",$_SERVER["SERVER_ADDR"]) +
					mt_rand(100000,999999));
		
		$this->setSetting("nic_key",$nic_key);
		
		$this->nic_key = $nic_key;
		
		return true;
	}
	
	/**
	* get nic_key
	* @access	public
	* @return	string	nic_key
	*/
	function getNICkey()
	{
		$this->nic_key = $this->getSetting("nic_key");
		
		if (empty($this->nic_key))
		{
			$this->setNICkey();
		}
		
		return $this->nic_key;
	}
	
	function getDefaultLanguage()
	{
		return $this->getSetting("language");
	}
	
	function setDefaultLanguage($a_lang_key)
	{
		$this->setSetting("language",$a_lang_key);
		$this->ini->setVariable("language","default",$a_lang_key);
		$this->ini->write();
		
		return true;
	}

	function getError()
	{
		$error = $this->error;
		$this->error = "";

		return $error;
	}
	
	function delete ($a_ini = true, $a_db = false, $a_files = false)
	{
		if ($a_ini === true and file_exists(ILIAS_ABSOLUTE_PATH."/".ILIAS_WEB_DIR."/".$this->getId()."/client.ini.php"))
		{
			unlink(CLIENT_WEB_DIR."/client.ini.php");
			$msg[] = "ini_deleted";
		}

		if ($a_db === true and $this->db_exists)
		{
			$this->db->query("DROP DATABASE ".$this->getDbName());
			$msg[] = "db_deleted";
		}

		if ($a_files === true and file_exists(CLIENT_WEB_DIR) and is_dir(CLIENT_WEB_DIR))
		{
			// rmdir();
			ilUtil::delDir(CLIENT_WEB_DIR);
			ilUtil::delDir(CLIENT_DATA_DIR);
			$msg[] = "files_deleted";
		}

		return $msg;
	}

	function create()
	{
		//var_dump($this->getDataDir());exit;
		// create base data dir
		if (!ilUtil::makeDir($this->getDataDir()))
		{
			$this->error = "could_not_create_base_data_dir :".$this->getDataDir();
			return false;
		}

		// create sub dirs in base data dir
		if (!ilUtil::makeDir($this->getDataDir()."/mail"))
		{
			$this->error = "could_not_create_mail_data_dir :".$this->getDataDir()."/mail";
			return false;
		}

		if (!ilUtil::makeDir($this->getDataDir()."/lm_data"))
		{
			$this->error = "could_not_create_lm_data_dir :".$this->getDataDir()."/lm_data";
			return false;
		}

		if (!ilUtil::makeDir($this->getDataDir()."/forum"))
		{
			$this->error = "could_not_create_forum_data_dir :".$this->getDataDir()."/forum";
			return false;
		}

		if (!ilUtil::makeDir($this->getDataDir()."/files"))
		{
			$this->error = "could_not_create_files_data_dir :".$this->getDataDir()."/files";
			return false;
		}

		// create base webspace dir
		if (!ilUtil::makeDir($this->getWebspaceDir()))
		{
			$this->error = "could_not_create_base_webspace_dir :".$this->getWebspaceDir();
			return false;
		}

		// create sub dirs in base webspace dir
		if (!ilUtil::makeDir($this->getWebspaceDir()."/lm_data"))
		{
			$this->error = "could_not_create_lm_webspace_dir :".$this->getWebspaceDir()."/lm_data";
			return false;
		}

		if (!ilUtil::makeDir($this->getWebspaceDir()."/usr_images"))
		{
			$this->error = "could_not_create_usr_images_webspace_dir :".$this->getWebspaceDir()."/usr_images";
			return false;
		}

		if (!ilUtil::makeDir($this->getWebspaceDir()."/mobs"))
		{
			$this->error = "could_not_create_mobs_webspace_dir :".$this->getWebspaceDir()."/mobs";
			return false;
		}

		if (!ilUtil::makeDir($this->getWebspaceDir()."/css"))
		{
			$this->error = "could_not_create_css_webspace_dir :".$this->getWebspaceDir()."/css";
			return false;
		}

		// write client ini
		if (!$this->ini->write())
		{
			$this->error = get_class($this).": ".$this->ini->getError();
			return false;
		}

		return true;
	}
}
?>
