<?php
/**
 * Admin Controller
 * 
 * Manages the admin panel
 *
 * @author Valentin Bora <contact@valentinbora.com>
 * @version 1.0
 * @category Joobsbox
 * @package Joobsbox_Controller
 */
 
/**
 * Manages the admin panel
 * @category Joobsbox
 * @package Joobsbox_Controller
 */
class AdminController extends Zend_Controller_Action
{
	private $pluginPath = "plugins/";
	private $currentPlugin;
	
	public function init() {
		$this->_conf = Zend_Registry::get("conf");
		configureTheme("_admin/" . $this->_conf->general->admin_theme);
		
		$this->plugins = array();
		$this->dashboardCandidates = array();
		foreach(new DirectoryIterator($this->pluginPath) as $plugin) {
			$name = $plugin->getFilename();
			if($plugin->isDir() && $name[0] != '.') {
				require_once "plugins/$name/$name.php";
				$class = new ReflectionClass(ucfirst($name));
				if($class->hasMethod('init')) {
					$this->plugins[$name] = array();
					if(file_exists($this->pluginPath . $name . '/config.ini.php')) {
						$this->plugins[$name] = new Zend_Config_Ini($this->pluginPath . $name . '/config.ini.php');
					}
				}
				if($class->hasMethod('dashboard')) {
					$this->dashboardCandidates[$name] = 1;
				}
			}
		}
		
		$this->view->pluginPath = $this->pluginPath;
		$this->view->plugins = $this->plugins;
		$this->view->locale  = Zend_Registry::get("Zend_Locale");
	}
	
	public function indexAction() {
		if(!$this->verifyAccess()) {
			$sess = new Zend_Session_Namespace("auth");
			$sess->loginSuccessRedirectUrl = $_SERVER['REQUEST_URI'];
			$this->_redirect("user/login");
		}
		
		$this->prepareDashboard();
		$this->view->currentPluginName = "dashboard";
		
		$viewRenderer = Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer');
		$viewRenderer->setNoController(false);
		$viewRenderer->setNoRender(false);
	}
	
	private function prepareDashboard() {
		$dashboardPlugins = file("config/adminDashboard.php");
		$this->view->dashboard = array();
		
		foreach($dashboardPlugins as $pluginName) {
			$pluginName = trim($pluginName);
			if(isset($this->dashboardCandidates[$pluginName])) {
				$plugin = $this->loadPlugin($pluginName, true);
				if(method_exists($plugin, "dashboard")) {
					$plugin->dashboard();
				}
				$viewRenderer = Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer');
				$this->view->dashboard[$pluginName] = array(
					"options"	=> $this->plugins[ucfirst($pluginName)],
					"content" 	=> $viewRenderer->view->render('dashboard.phtml')
				);
			}
		}
	}
	
	private function router() {
		if(!$this->verifyAccess()) {
			$this->_redirect("user/login");
		}
		
		$action = $this->getRequest()->getParam('action');
		$pluginNames = array_keys($this->plugins);
		if(($pluginIndex = array_search($action, array_map('strtolower', array_keys($this->plugins)))) !== FALSE) {
			$this->loadPlugin($pluginNames[$pluginIndex]);
		}
	}
	
	private function loadPlugin($pluginName, $return = true) {
		$pluginUrl = $_SERVER['REQUEST_URI'];
		if($pluginUrl[strlen($pluginUrl)-1] != '/') {
			$pluginUrl .= '/';
		}
		$this->view->pluginUrl = $pluginUrl;
		
		require_once $this->pluginPath . $pluginName . '/' . $pluginName . '.php';
		$plugin = new $pluginName;
		$this->view->currentPluginName = $pluginName;
		$plugin->view = $this->view;
		$plugin->path = $plugin->view->path = $this->view->baseUrl . '/' . $this->pluginPath . $pluginName . "/";
		$plugin->_helper = $this->_helper;
		$plugin->request = $this->getRequest();
		$plugin->init();
		
		if($return) {
			$controllerAction = $this->getRequest()->getParam('action');
			$action = substr($_SERVER['REQUEST_URI'], strpos($_SERVER['REQUEST_URI'], $controllerAction)+strlen($controllerAction)+1);
			if($pos = strpos($action, '/') !== FALSE) {
				$action = substr($action, 0, strpos($action, '/'));
			}
			$action .= "Action";
			if(method_exists($plugin, $action)) {
				call_user_func(array($plugin, $action));
			} elseif(method_exists($plugin, "indexAction")) {
				call_user_func(array($plugin, "indexAction"));
			}
		}
		
		$translate = Zend_Registry::get("Zend_Translate");
		$locale	   = Zend_Registry::get("Zend_Locale");
		$translate->addTranslation($this->pluginPath . $pluginName . '/languages/', $locale);
		Zend_Registry::set("Translation_Hash", $translate->getMessages());
		Zend_Registry::get("TranslationHelper")->regenerateHash();
		
		$viewRenderer = Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer'); 
		$viewRenderer->view->addScriptPath($this->pluginPath . $pluginName . '/views');
		$viewRenderer->setNoController(true);
		$viewRenderer->setViewScriptPathNoControllerSpec(':action.:suffix');
		
		if(!$return) {
			$viewRenderer->setNoRender();
		}
		
		return $plugin;
	}
	
	public function verifyAccess() {
		return Zend_Auth::getInstance()->hasIdentity();
	}
	
	public function __call($methodName, $params) {
		if(!method_exists($this, $methodName)) {
			$this->router();
		}
	}
}