
/**
 * Work with smscaptcha.ru service
 *
 * @author smscaptcha.ru <info@smscaptcha.ru>
 */
class Smscaptcha{
    /**
	 * Data type phone number
     * 
     * @var string
     */
	const TEL = "PHN";
    /**
	 * Data type Email address
     * 
     * @var string
     */
	const EMAIL = "EML";
	
    /**
     * @var string
     */
	const STATUS_VERIFIED		= "V";
    /**
     * @var string
     */
	const STATUS_NOT_VERIFIED	= "NV";
    /**
     * @var string
     */
	const STATUS_TIMEOUT		= "TO";
	
    /**
     * Get last error
     *
     * @return string
     */
	private static function lastError(){
		return self::getVar('last_error');
	}
    /**
     * Write to log
     *
     * @param string $message
     */
	private static function toLog($message){
		if(defined('SMSCAPTCHA_LOG_FILENAME')){
			file_put_contents(SMSCAPTCHA_LOG_FILENAME,'['.date('d.m.Y H:i:s').']	'.$message,FILE_APPEND);
		}
		self::setVar('last_error',$message);
	}
    /**
     * Start operation
     *
     * @param string $data_type
     * @param string $data
     * @return string
     */
	public static function operationStart($data_type,$data){
		try{
			$verify_id = self::send($data_type,$data,self::getVar('verify_id'));
			if(empty($verify_id)) throw new Exception('Empty verify_id');
			
			self::setVar('verify_id',$verify_id);
			self::setVar('data',$data);
			self::setVar('data_type',$data_type);
			
			return $verify_id;
		}catch(\Exception $exc){
			toLog('operationStart: '.$exc->getMessage());
		}
		return NULL;
	}
    /**
     * Check current operation
     *
     * @param string $code
     * @return bool
     */
	public static function operationCheck($code=NULL){
		try{
			$verify_id	= self::getVar('verify_id');
			$data_type	= self::getVar('data_type');
			$data		= self::getVar('data');
			
			if(empty($data_type)) throw new Exception('No data_type');
			if(empty($verify_id)) throw new Exception('No operation');
			if(empty($data)) throw new Exception('No data');
			
			
			if($data_type == self::EMAIL){
				$status = self::status($data,$verify_id);
				if($status === self::STATUS_TIMEOUT){
					self::operationStart($data_type,$data);
					throw new Exception(SMSCAPTCHA_MESSAGE_EMAIL_RESEND);
				}elseif($status === self::STATUS_NOT_VERIFIED){
					throw new Exception(SMSCAPTCHA_MESSAGE_CODE_NV);
				}
			}elseif($data_type == self::TEL){
				if($code === NULL && isset($_REQUEST['smscaptcha-code'])) $code = $_REQUEST['smscaptcha-code'];
				if(empty($code)) throw new Exception('No code');
				
				$verify_result = self::verify($code,$verify_id);
				if($verify_result['STATUS'] != "OK"){
					if(self::status($data,$verify_id) === self::STATUS_TIMEOUT){
						self::operationStart($data_type,$data);
						throw new Exception(SMSCAPTCHA_MESSAGE_CODE_RESEND);
					}
					throw new Exception($verify_result['MESSAGE']);
				}
			}
			return true;
		}catch(\Exception $exc){
			toLog('operationCheck: '.$exc->getMessage());
		}
		return false;
	}
    /**
     * Invalidate current operation
     *
     * @return bool
     */
	public static function operationInvalidate($code=NULL){
		try{
			$verify_id	= self::send($data_type,$data);
			$data		= self::getVar('data');
			
			if(empty($verify_id)) throw new Exception('Empty verify_id');
			if(empty($data)) throw new Exception('Empty data');
			
				
			self::status($data,$verify_id,true);
			
			self::setVar('verify_id',NULL);
			self::setVar('data',NULL);
			self::setVar('data_type',NULL);
			self::setVar('last_error',NULL);
			
			return true;
		}catch(\Exception $exc){
			toLog('operationStart: '.$exc->getMessage());
		}
		return false;
	}
    /**
     * Get form with input field to show the visitor
     *
     * @return string
     */
	public static function operationForm(){
		return '<form method="POST" name="smscaptcha-form" action=".">'.self::operationInputfield().'<button type="submit">'.SMSCAPTCHA_MESSAGE_CODE_SEND.'</button></form>';
	}
    /**
     * Get input field to show the visitor
     *
     * @return string
     */
	public static function operationInputfield(){
		try{
			$verify_id = self::getVar('verify_id');
			if(empty($verify_id)) throw new Exception('No operation');
			return '<input type="text" name="smscaptcha-code" value=""/>';
		}catch(\Exception $exc){
			toLog('operationStart: '.$exc->getMessage());
		}
		return NULL;
	}
    /**
     * Get session variable
     *
     * @param string $var_name
     * @return mixed
     */
	private static function getVar($var_name){
		if(session_status() == PHP_SESSION_NONE){
			session_start();
		}
		return isset($_SESSION[$var_name])?$_SESSION[$var_name]:NULL;
	}
    /**
     * Set session variable
     *
     * @param string $var_name
     * @param mixed $var_value
     */
	private static function setVar($var_name,$var_value){
		if(session_status() == PHP_SESSION_NONE){
			session_start();
		}
		$_SESSION[$var_name]=$var_value;
	}
    /**
     * Verify code
     *
     * @param string $code
     * @param string $id
     * @return Array
     */
	public static function verify($code,$id){
		$resp = self::getJSON('https://smscaptcha.ru/api/verify/', [
			'public_key'=> SMSCAPTCHA_PUBLIC_KEY,
			'code'		=> $code,
			'id'		=> $id,
		]);
		return $resp;
	}
    /**
     * Send code request
     *
     * @param string $data_type
     * @param string $data
     * @param string $id
     * @return string
     */
	public static function send($data_type,$data,$id=NULL){
		$post_data = [
			'public_key'=> SMSCAPTCHA_PUBLIC_KEY,
			'data_type'	=> $data_type,
			'data'		=> $data,
		];
		if($id){
			$post_data['id'] = $id;
		}
		$resp = self::getJSON('https://smscaptcha.ru/api/send/', $post_data);
		if($resp['STATUS'] != "OK"){
			throw new \Exception($resp['MESSAGE']);
		}
		return $resp['DATA'];
	}
    /**
     * Get status
     *
     * @param string $data
     * @param string $id
     * @param bool $complete
     * @return string
     */
	public static function status($data,$id,$complete=false){
		$post_data = [
			'data'	=> $data,
			'id'	=> $id,
		];
		if($complete){
			$post_data['private_key'] = SMSCAPTCHA_PRIVATE_KEY;
			$post_data['complete'] = 1;
		}else{
			$post_data['public_key'] = SMSCAPTCHA_PUBLIC_KEY;
		}
		$resp = self::getJSON('https://smscaptcha.ru/api/status/', $post_data);
		if($resp['STATUS'] != "OK"){
			throw new \Exception($resp['MESSAGE']);
		}
		return $resp['DATA'];
	}
    /**
     * Send request
     *
     * @param string $url
     * @param Array $post_data
     * @return Array
     */
	private static function getJSON($url,$post_data){
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$resp = json_decode(curl_exec($ch), true);
		curl_close ($ch);
		if(!$resp){
			throw new \Exception("Empty resp");
		}
		return $resp;
	}
}
