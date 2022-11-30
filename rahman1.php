<?php
ob_start();

define('SITE_URL',site_url()); 
require_once( ABSPATH.'wp-admin/includes/user.php' ); 
require_once( ABSPATH . 'wp-admin/includes/image.php' );
require_once( ABSPATH . 'wp-admin/includes/file.php' ); 
require_once( ABSPATH . 'wp-admin/includes/media.php' ); 
define('ADMIN_EMAIL', 'no-reply@knoxweb.com');
  
//require( ABSPATH . '/wp-load.php' );  
  //updateDeviceToken  
/**
 *  
 * @wordpress-plugin 
 * Plugin Name:       CRC Rest Api
 * Description:       This Plugin contain all rest api.
 * Version:           1.0
 * Author:            RA
 */ 
 
 use Firebase\JWT\JWT;

 class CRC_REST_API extends WP_REST_Controller {
   	private $api_namespace;
	private $api_version;
	private $required_capability;
	public $user_token;
	public $user_id;
	public $firebase;
	public function __construct() {
		$this->api_namespace = 'mobileapi/v';
		$this->api_version = '1';
		$this->required_capability = 'read';
		$this->init();
		/*------- Start: Validate Token Section -------*/
		$headers = getallheaders(); 
		if (isset($headers['Authorization'])) { 
        	if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) { 
            	$this->user_token =  $matches[1]; 
        	} 
        }
        /*------- End: Validate Token Section -------*/
	}
	
    public function register_routes() {  
		$namespace = $this->api_namespace . $this->api_version;
	    $privateItems = array('get_profile','update_profile','getUserProfileById'); //Api Name 
	    $getItems=array('patient_checkin_date','get_my_notifications','get_notification_count','remove_device_token','completed_checkin','otp_password','get_user_chat','get_user_chats','get_support_chat','get_calender_schedule','get_patients_by_date','get_about_us','get_patient_report','get_calender_checkin','get_patients','checkin_questions','get_privacy_policy','get_tutorial');
	    $publicItems  = array('clear_notification','test_notfication','save_device_detail','support_chat','register','retrive_password','checkin_submit','forgot_password','verify_otp','update_new_password','get_term_condition','contact_us', 'delete_account_request');
		foreach($privateItems as $Item){
		  	register_rest_route( $namespace, '/'.$Item, array(
			   array( 
			       'methods' => 'POST', 
			       'callback' => array( $this, $Item), 
			       'permission_callback' => !empty($this->user_token)?'__return_true':'__return_false'
			       ),
	    	    )  
	    	);  
		}
		
		foreach($getItems as $Item){
		  	register_rest_route( $namespace, '/'.$Item, array(
			   array( 
			       'methods' => 'GET', 
			       'callback' => array( $this, $Item )
			       ),
	    	    )  
	    	);  
		}
		
		foreach($publicItems as $Item){
		  	register_rest_route( $namespace, '/'.$Item, array(
			   array( 
			       'methods' => 'POST', 
			       'callback' => array( $this, $Item )
			       ),
	    	    )  
	    	);  
		}
	}
	
	public function init(){
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'rest_api_init', function() {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
			add_filter( 'rest_pre_serve_request', function( $value ) {
				header( 'Access-Control-Allow-Origin: *' );
				header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE' );
				header( 'Access-Control-Allow-Credentials: true' );
				return $value;
			});
		}, 15 );
		$namespace = $this->api_namespace . $this->api_version;
// 		add_filter( 'jwt_auth_whitelist', function ( $endpoints ) {
//             return array(
//                 '/wp-json/'.$namespace.'/v1/retrivePass',
//             );
//         } );
	}
	
	public function isUserExists($user)
    {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->users WHERE ID = %d", $user));
        if ($count == 1) {return true;} else {return false;}
    }
        
	public function getUserIdByToken($token)
    {
        $decoded_array = array();
        $user_id = 0;
        if ($token) {
            try{
                $decoded = JWT::decode($token, JWT_AUTH_SECRET_KEY, array('HS256'));
                $decoded_array = (array) $decoded;
            }
            catch(\Firebase\JWT\ExpiredException $e){

                return false;
            }
        }
        if (count($decoded) > 0) {
            $user_id = $decoded_array['data']->user->id;
        }
        if ($this->isUserExists($user_id)) {
            return $user_id;
        } else {
            return false;
        }
    }
    
    function jwt_auth($data, $user) {
        unset($data['user_nicename']);
        unset($data['user_display_name']); 
        $site_url = site_url();
        
        $delete_account = get_user_meta($user->ID,'delete_account',true);
        if($delete_account == 'yes'){
            $data['status'] = 'error';
            $data['error_msg'] = 'Your account is suspended. We are processing to delete your account permanently.';
            $data['error_code'] = 'delete_account';
        
            return new WP_Rest_response($data,403);    
        
        }
        
        $is_disabled = get_user_meta($user->ID,"delete_account",true);
        if($is_disabled == "yes"){
            return $this->errorResponse('This account has been suspended.');
        }else{
            $result = $this->getProfile( $user->ID );
            $tutorial = get_user_meta($user->ID,'tutorial',true);
            
            $result['token'] =  $data['token'];
            return $this->successResponse('User Logged in successfully',$result);
        }
    }

    private function isValidToken(){
    	$this->user_id  = $this->getUserIdByToken($this->user_token);
    }
	
	private function successResponse($message='',$data=array(),$total = array(),$detail = array(),$chat_with=array()){ 
        $response =array();
        $response['status'] = "success";
        $response['error_type'] = "";
        $response['message'] =$message;
        if(!empty($chat_with)){
            $response['chat_with']=$chat_with;
        }
        $response['data'] = $data;
        if(!empty($total)){
            $response['pagination'] = $total;
        }
        if(!empty($detail)){
            $response['patient_details'] = $detail;
        }

        return new WP_REST_Response($response, 200);  
    }
    private function errorResponse($message='',$type='ERROR' , $statusCode=400){
        $response = array();
        $response['status'] = "failed";
        $response['error_type'] = $type;
        $response['message'] =$message;
        $response['data'] = array();
        return new WP_REST_Response($response, $statusCode); 
    } 
    
    // public function test_notfication($request){
    //     $this->sendPushServer(2,"Checkin","Check is complete","Check In",2,0);
    // }
    
    public function sendPushServer($sender_id = "", $type = "", $msg = "", $title = "", $receiver_id, $post_id = "", $checkin_status="", $patient_id="", $checkin_date=""){
        global $wpdb;
        
        if($type == "chat"){
            $insert = $wpdb->insert('wp_save_notification', array( 
                'receiver_id' => $receiver_id,
                'sender_id'  => $sender_id,
                'notification_msg' => $msg,
                'type' => $type,
                'title' => $title,
                'date'=> date("Y-m-d H:i:s")
            ));
        }
        $query = "SELECT * FROM `wp_users_device_details` WHERE `user_id`='$receiver_id' and `is_user_logged_in` = '1'";
        
        $token = array();
        $results = $wpdb->get_results($query);

        foreach ($results as $data) {
            $token[] = $data->device_token;
        }
        
        $get_notification = $wpdb->get_row("SELECT * FROM `wp_save_notification` WHERE `patient_id`='$patient_id' AND `checkin_date`='$checkin_date' AND `post_id`='$post_id'");
        $get_notification_id = $get_notification->id;
        if(!empty($get_notification)){
            
            $update = $wpdb->update('wp_save_notification', array( 
                'checkin_status'=>$checkin_status,
                'date'=> date("Y-m-d H:i:s")
            ),
            array(
                'id'=>$get_notification_id
            ));
            
        }else{
            $get_checkin = $wpdb->get_row("SELECT * FROM `wp_save_notification` WHERE `patient_id`='$patient_id' AND `checkin_date`='$checkin_date' AND `sender_id`='$sender_id' AND `receiver_id`='$receiver_id' AND `checkin_status`='0'");
            $get_checkin = $get_notification->id;
            if(!empty($get_checkin)){
                $wpdb->update('wp_save_notification',array(
                    'post_id' => $post_id,
                    'receiver_id' => $receiver_id,
                    'sender_id'  => $sender_id,
                    'notification_msg' => $msg,
                    'type' => $type,
                    'title' => $title,
                    'checkin_status'=>$checkin_status,
                    'patient_id'=> $patient_id,
                    'checkin_date'=> $checkin_date,
                    'date'=> date("Y-m-d H:i:s") 
                ),array(
                    'id' => $get_checkin
                ));
            }else{
                $insert = $wpdb->insert('wp_save_notification', array( 
                    'post_id' => $post_id,
                    'receiver_id' => $receiver_id,
                    'sender_id'  => $sender_id,
                    'notification_msg' => $msg,
                    'type' => $type,
                    'title' => $title,
                    'checkin_status'=>$checkin_status,
                    'patient_id'=> $patient_id,
                    'checkin_date'=> $checkin_date,
                    'date'=> date("Y-m-d H:i:s")
                ));
            }
        }
        
            $data=array('type'=>$type,"post_id"=>$post_id,"sender_id"=>$sender_id,"receiver_id"=>$receiver_id);
            if($type == 'check_in'){
                $dataType = array(
                    'type' => $type,
                    'checkin_status'=>$checkin_status,
                    'patient_id'=> $patient_id,
                    'checkin_date'=> $checkin_date
                );
            }elseif($type == 'chat'){
                $dataType = array(
                    'type' => $type,
                    'sender_id'  => $sender_id,
                );
            }

            $res = $this->sendMessage($msg, $token, $data, $title,$dataType);
    }
    
    public function sendMessage($msgData, $device_token, $data, $title,$dataType) {
        
    	$data = array(
            'source' => 'Legacy Caregiving',
            // 'msgshow' => $name,
            'body' => $msgData,
            'title' => $title,
            'sound' => "default",
            // 'color' => "#8e2c93",
            // 'type' => $type,
            'appid' =>'f2858587-d36c-44df-a234-483cd766fe60',
            // 'channel'=>$channel,
            // 'uid'=>$uid,
            // 'caller_name'=>$name,
            // 'type'=>'Video Call'
        );
        
        // if(isset($platform) && $platform == 'ios'){
       
                $fcmFields = array(
                    'registration_ids' => $device_token,
                    'priority' => 'high',
                    'notification' => $data,
                    'data' => $dataType
                );
            
        // }
        // else{
               
        //          $fcmFields = array(
        //         'registration_ids' => $devide_ids,
        //         'priority' => 'high',
        //         'data' => $data
        //     );
               
        // }
        
        $headers = array(
            'Authorization: key=AAAAecjtL5w:APA91bEugoV-Y8SK_vkTvKgR33SdZZ8IJ7Kcc5jLP-hdBzkCbcRJzm85k2XH0R0DiFrc__ZSnY--AEoMrJBuSzDoez4biIDbKTly4vmk7J8nY71AXAjAqYbKG0Pekb3qPy4DpkbAS29d',
            'Content-Type: application/json'
        );
        
        
        $ch = curl_init();
        curl_setopt( $ch,CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send' );
        curl_setopt( $ch,CURLOPT_POST, true );
        curl_setopt( $ch,CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $ch,CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch,CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch,CURLOPT_POSTFIELDS,json_encode($fcmFields));
        $result = curl_exec($ch);
        
        curl_close( $ch );
    }
    
    //Function for get user profile by passing id parameter
    public function getProfile($user_id) {
     	global $wpdb;
        $userInfo = get_user_by( 'ID', $user_id );
        $full_name = get_user_meta( $user_id, 'full_name', true );
        $first_name = get_user_meta( $user_id, 'first_name', true );
        $last_name = get_user_meta( $user_id, 'last_name', true );
        $phone = get_user_meta( $user_id, 'phone', true );
        $wp_user_profile = get_user_meta($user_id, 'user_img' , true );
        $profile_pic_link = get_post_meta($wp_user_profile,'_wp_attached_file',true);
        if(empty($wp_user_profile)){
            $profile_img_link = 'https://gravatar.com/avatar/dba6bae8c566f9d4041fb9cd9ada7741?d=identicon&f=y';
        } else {
            $profile_img_link = SITE_URL.'/wp-content/uploads/'.$profile_pic_link;
        }
        $userData = get_userdata($user_id);
        
        $result = array(
            'user_id'     => $userInfo->ID,
            'user_email'  => $userInfo->user_email,
            'first_name'  => !empty($first_name)?$first_name:'',
            'last_name'   => !empty($last_name)?$last_name:'',
            'full_name'   => !empty($full_name)?$full_name:'',
            'phone'       => !empty($phone)?$phone:'',
            'user_img'    => $profile_img_link,
        );
        if(!empty($userInfo)) {
            return $result;
        } else {
            return 0;
        }
    } 
    
    // public function delete_user(){
    //     global $wpdb;
    // 	$param = $request->get_params();
    //     $this->isValidToken();
    //     $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
    //     $reason = trim($param['reason']);
    // }
    
    //Function for update user profile
    public function update_profile($request){
    	global $wpdb;
    	$param = $request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        
        if(empty($user_id)){
            return $this->errorResponse('Please enter the valid token.');
        } 
        else{
            
            !empty($param['first_name'])?update_user_meta( $user_id, 'first_name', $param['first_name']):'';
            !empty($param['last_name'])?update_user_meta( $user_id, 'last_name', $param['last_name']):'';
            update_user_meta($user_id, 'full_name',$param['first_name']." ".$param['last_name']);
            !empty($param['phone'])?update_user_meta( $user_id, 'phone', $param['phone']):'';
            !empty($param['details'])?update_user_meta( $user_id, 'details', $param['details']):'';
            !empty($param['address'])?update_user_meta( $user_id, 'address', $param['address']):'';
            // !empty($param['attachment_id'])?update_user_meta( $user_id, 'wp_user_avatar', $param['attachment_id']):'';
               
            if(!empty($_FILES['attachment_id'])){
                $userProfileImgId = media_handle_upload('attachment_id', $user_id);
                update_user_meta($user_id,'user_img',$userProfileImgId);
            }
            $result = $this->getProfile($user_id);
    	    if(!empty($result)){
    	    	return $this->successResponse('User profile updated successfully', $result);
    	    } else {
    	    	return $this->errorResponse('No record found');
    	    }
        }
    }
    
    // Function for get user profile by Id
    public function getUserProfileById($request){
        $param = $request->get_params();
        $this->isValidToken();
        $id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $userInfo = get_user_by( 'ID', $id );
        $first_name = get_user_meta( $id, 'first_name', true );
        $phone = get_user_meta( $id, 'phone', true );
        $address = get_user_meta( $id, 'address', true );
        $address = !empty($address)?$address:'';
        $wp_user_profile = get_user_meta($id, 'profile_img' , true );
        $profile_pic_link = get_post_meta($wp_user_profile,'_wp_attached_file',true);
        
        if(empty($wp_user_profile)){
            $profile_img_link = 'https://gravatar.com/avatar/dba6bae8c566f9d4041fb9cd9ada7741?d=identicon&f=y';
        } else {
            $profile_img_link = SITE_URL.'/wp-content/uploads/'.$profile_pic_link;
        }
        $result = $this->getProfile($id);
        if(!empty($userInfo)) {
            return $this->successResponse('',$result);
        } else {
           return $this->errorResponse('Please try again.');
        }
    }
    
    //Function for change password
    public function update_new_password($request){
      global $wpdb;
      $param = $request->get_params();
      $user    = $param['user_id'];
      $password = $param['matching_passwords']['password'];
      $confirm_password = $param['matching_passwords']['confirmPassword'];
      $user_info = get_user_by('ID',$user);
      $user_id = $user_info->ID;
      if($user_id){
          if($password === $confirm_password){
              wp_set_password($password,$user_id);
              return $this->successResponse('Your Password has been changed!');
          }else{
              return $this->errorResponse('Password does not match. Please try again!');
          }
      }else{
          return $this->errorResponse('User not found.');
      }
    }
    
    // Function for register user
    public function register($request){
      global $wpdb;
      $param = $request->get_params();
      $first_name = $param['first_name'];
      $last_name = $param['last_name'];
      $email = $param['email'];
      $password = $param['password'];
      $confirm_password = $param['confirm_password'];
      $phone = $param['phone'];
      
      if(!is_email($email)){
          return $this->errorResponse('This is not a Valid Email.');
      }
      if(email_exists($email)){
          return $this->errorResponse('Email already exists.');
      }
      if($password == $confirm_password){
            $fullname = $first_name." ".$last_name;
            $user_id = wp_create_user($email,$password,$email);
            $user = new WP_User( $user_id );
            update_user_meta( $user_id, 'full_name', $fullname);
            update_user_meta($user_id, 'first_name', $first_name);
            update_user_meta($user_id, 'last_name', $last_name);
            update_user_meta( $user_id, 'phone', $phone);
            $data = $this->getProfile($user_id);
            if($user_id){
               return $this->successResponse('User registration successfully.',$data); 
            }else{
               return $this->errorResponse('Please try again.'); 
            }
        }else{
            return $this->errorResponse('Password and confirm password does not match.'); 
        }
    }
    
    
    public function user_id_exists($user){
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->users WHERE ID = %d", $user));
        if($count == 1){
            return true;
        }else{
            return false;
        }
    }

    // Function for get user profile
    public function get_profile($request){
    	global $wpdb;
    	$param = $request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        if(empty($user_id)) {
            return errorResponse('Please enter the valid token.');
        } else {
            
            $result = $this->getProfile($user_id);
    
    	    if(!empty($result)){
    	    	return $this->successResponse('User Info fetched successfully', $result);
    	    } else {
    	    	return $this->errorResponse('No record found');
    	    }
        }
    }
    

    //Function for set otp
     public function otp_password($request){
        global $wpdb;
        $param = $request->get_params();
        $user_login = sanitize_text_field($param['email']);
        if(empty($user_login)){
             return $this->errorResponse('User email is empty');
        } elseif(!is_email($user_login)){
            return $this->errorResponse('Please provide valid email');
        } elseif(strpos($user_login,'@')){
            $user_data = get_user_by('email',trim($user_login));
        }
        if(!$user_data){
           return $this->errorResponse('Email not matched with our records');
        }
        $user_email=$user_data->user_email;
        $user_id=$user_data->ID;
        if($user_id > 0){
            $digits = 4;
            $rand_pass = rand(1000,9999);
            $meta_otp=update_user_meta($user_id,"otp",$rand_pass);
            update_user_meta($user_id,'otp_send_time',date('Y-m-d h:i:s'));
            // update_user_meta($user_id,"is_verified",0);
            if($meta_otp){
                $message = "4 digit configuration code $rand_pass";
                $message = __('Hello ,') . "<br><br>";
                $message .= __('You recently created account with email <b>'.$user_email.'</b> on <b>Legacy Care Giving</b>.<br>To verify this
                email address belongs to you, please enter the code below on the email on your confirmation page') . "<br><br>";
                $message .=__('<big><b> '.$rand_pass.'<b></big>')."<br><br>";
                $message .= __('Sincerely') . "<br>";
                $message .= __('Support Team') . "<br>";
                $headers = array('Content-Type: text/html; charset=UTF-8');
                $subject = "Confirmation code for Legacy Care Giving";
                wp_mail($user_email,$subject,$message, $headers);
                
                $result = array(
                    "user_email" => $user_email,
                    "user_id"    => $user_id
                    );
                
                return $this->successResponse('A Special code has been sent to your email.',$result);   
            } else {
                return $this->errorResponse('Something Went Wrong');
            }
            
        }
        
    }
    
    
    public function forgot_password($request){
        global $wpdb;
        $param = $request->get_params();
        $user_login = sanitize_text_field($param['email']);
        if(empty($user_login)){
             return $this->errorResponse('User email is empty');
        } elseif(!is_email($user_login)){
            return $this->errorResponse('Please provide valid email');
        } elseif(strpos($user_login,'@')){
            $user_data = get_user_by('email',trim($user_login));
        }
        if(!$user_data){
           return $this->errorResponse('Email not matched with our records');
        }
        $user_email=$user_data->user_email;
        $user_id=$user_data->ID;
        if($user_id > 0){
            $digits = 4;
            $rand_pass = rand(1000,9999);
            $meta_otp=update_user_meta($user_id,"otp",$rand_pass);
            update_user_meta($user_id,'otp_send_time',date('Y-m-d h:i:s'));
            // update_user_meta($user_id,"is_verified",0);
            if($meta_otp){
                $message = "4 digit configuration code $rand_pass";
                $message = __('Hello ,') . "<br><br>";
                $message .= __('You recently created account with email <b>'.$user_email.'</b> on <b>Legacy Care Giving</b>.<br>To verify this
                email address belongs to you, please enter the code below on the email on your confirmation page') . "<br><br>";
                $message .=__('<big><b> '.$rand_pass.'<b></big>')."<br><br>";
                $message .= __('Sincerely') . "<br>";
                $message .= __('Support Team') . "<br>";
                $headers = array('Content-Type: text/html; charset=UTF-8');
                $subject = "Confirmation code for Legacy Care Giving";
                wp_mail($user_email,$subject,$message, $headers);
                
                $result = array(
                    "user_email" => $user_email,
                    "user_id"    => $user_id
                    );
                
                return $this->successResponse('A Special code has been sent to your email.',$result);   
            } else {
                return $this->errorResponse('Something Went Wrong');
            }
            
        }
        
    }
    
    
    //Function for verify otp
    public function verify_otp($request)
    {
        global $wpdb;
        $param = $request->get_params();
        $user_login = $param['user_id'];
        $verify_otp = $param['forget_otp'];
        
        $user_info = get_user_by('ID',$user_login);
        $user_id = $user_info->ID;
        if($user_id){
            if(empty($verify_otp)){
                return $this->errorResponse('Otp field is empty');
            } else {
                $user_meta_otp = get_user_meta($user_id,"otp",true);
                if($user_meta_otp == $verify_otp){
                    update_user_meta($user_id,"otp_send_time",null);
                   $verified = update_user_meta($user_id,"otp",null);
                   if($verified){
                       $result = array(
                           "user_id" => $user_id
                           );
                       return $this->successResponse('Otp Verified Successfully',$result); 
                   }
                } else {
                    return $this->errorResponse('Please enter valid otp');
                }
            }
            
        }
    }

    
    //Function for get privacy policy
    public function get_privacy_policy($request){
           global $wpdb;
            $param = $request->get_params();
            $page_data = $wpdb->get_row("select `post_title`,`post_content` from wp_posts where `post_name`='privacy-policy'",ARRAY_A);
            $result['post_title']= $page_data['post_title'];
            $result['post_content']= $page_data['post_content'];
        return $this->successResponse('Privacy policy data retrieve successfully.',$result);
    }
    
    public function get_term_condition($request)
    {
        global $wpdb;
        $param=$request->get_params();
         $page_data = $wpdb->get_row("select `post_title`,`post_content` from wp_posts where `post_name`='terms-and-conditions'",ARRAY_A);
            $result['post_title']= $page_data['post_title'];
            $result['post_content']= $page_data['post_content'];
        return $this->successResponse('Term and Conditions data retrieve successfully.',$result);
    }
    
    
    //Function for get terms and condition
    // public function get_term_conditions($request){
    //       global $wpdb;
    //       $page_id=885;
    //         $param = $request->get_params();
    //         $page_data = $wpdb->get_row("select `post_title`,`post_content` from wp_posts where `post_name`='terms-of-use'",ARRAY_A);
    //         $result['post_title']= $page_data['post_title'];
    //         $result['post_content']= $page_data['post_content'];
    //     return $this->successResponse('',$result);
    // }
    
    
    //Function for get about us
    public function get_about_us($request)
    {
        global $wpdb;
        $param=$request->get_params();
         $page_data = $wpdb->get_row("select `post_title`,`post_content` from wp_posts where `post_name`='about-us'",ARRAY_A);
            $result['post_title']= $page_data['post_title'];
            $result['post_content']= $page_data['post_content'];
        return $this->successResponse('About us data retrieve successfully.',$result);
    }
    
    //Function for get tutorial
    public function get_tutorial($request)
    {
        global $wpdb;
        $param=$request->get_params();
        $args = array(
            "post_type" => "tutorial_post",
            "category_name" => "tutorial",
            "taxonomy" => "category"
            );
        $data =  new WP_Query($args);
        $postData = $data->posts;
        $tutorial_array = array();
        foreach($postData as $posts){
            $post_id = $posts->ID;
            $post_title = $posts->post_title;
            $post_content = $posts->post_content;
            $post_meta_id = get_post_meta($post_id);
            $post_img_id = $post_meta_id['_thumbnail_id'][0];
            $post_img_url = get_post_meta($post_img_id);
            $feature_img = $post_img_url['_wp_attached_file'][0];
            if(empty($post_img_id)){
                $image_url = "";
            }else{
               $image_url = "https://legacycaregiving.betaplanets.com/wp-content/uploads/".$feature_img.""; 
            }
            
            $tutorial_array[] = array(
                "id" => $post_id,
                "title" => $post_title,
                "image" => $image_url,
                "content" => $post_content,
            );
        }
        return $this->successResponse('Tutorial data retrieve successfully.',$tutorial_array);
    }
    
    
    public function patient_checkin_date($request){
        global $wpdb;
        $param = $request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $date = $param['date'];
        $p_id = $param['pid'];
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }else{
            $new_date = date("Y-m-d",strtotime($date));
            $args = array(
            "post_type" => "checkin",
            // "author" => $user_id,
            "post_status" => "publish",
            // "fields" =>'ids',
            'meta_query' => array(
                'relation' => 'AND',
                    array(
                       'key' => 'patient_id',
                       'value' => $p_id
                    ),
                    array(
                         'key' => 'checkin_date',
                         'value' => $new_date
                    )
                )
            );
            $data = new WP_Query($args);
		    $checkin_posts = $data->posts;
		    if(empty($checkin_posts)){
		        $f_name = get_user_meta($p_id,"first_name",true);
		        $l_name = get_user_meta($p_id,"last_name",true);
		        $first_name = isset($f_name)?$f_name:"";
		        $last_name =isset($l_name)?$l_name:"";
		        $full_name = $first_name." ".$last_name;
		        $checkin_array[]=array(
		            "patient_id"=> $p_id,
		            "name"=>$full_name,
		            "checkin_status"=>0,
		            "checkin_time"=>"00:00",
		            "checkin_date"=>$date
		        );
		        return $this->successResponse("Patient checkin get successfully.",$checkin_array);
		    }else{
		        foreach($checkin_posts as $datas){
		            $post_id = $datas->ID;
                    $f_name = get_user_meta($p_id,"first_name",true);
                    $l_name = get_user_meta($p_id,"last_name",true);
                    $first_name = isset($f_name)?$f_name:"";
                    $last_name =isset($l_name)?$l_name:"";
                    $full_name = $first_name." ".$last_name;
		            $status = get_post_meta($post_id,"checkin_status",true);
		            $get_date = get_post_meta($post_id,"checkin_date",true);
		            $date = date("m/d/Y",strtotime($get_date));
		            $get_time = get_post_meta($post_id,"checkin_time",true);
		            $checkin_array[]=array(
                        "patient_id"=> $p_id,
                        "name"=>$full_name,
                        "checkin_status"=>$status,
                        "checkin_time"=>$get_time,
                        "checkin_date"=>$date
		            );
		        }
		        return $this->successResponse("Patient checkin get successfully.",$checkin_array);
		    }
        }
    }
    
     //Function for completed checkin
    public function completed_checkin($request){
        global $wpdb;
        $param=$request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $p_id = $param['pid'];
        $paged = !empty($param['paged']) ? $param['paged'] : 1;
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }else{
            if(isset($p_id)){
                $f_name = get_user_meta($p_id,"first_name",true);
                $l_name = get_user_meta($p_id,"last_name",true);
                $first_name = isset($f_name)?$f_name:"";
                $last_name = isset($l_name)?$l_name:"";
                $full_name = $first_name." ".$last_name;
                // $patients = $wpdb->get_row("SELECT * FROM `patient_relations` WHERE `patient_id`='$p_id'");
                // $created_date = $patients->created_at;
                $user_data = get_userdata($p_id);
                $created_date = $user_data->user_registered;
                $date = date("Y-m-d",strtotime($created_date));
                $get_day = date("d",strtotime($created_date));
                $get_month = date("m",strtotime($created_date));
                $get_year = date("Y",strtotime($created_date));
                
                $month = date("m");
                $year = date("Y");
                $date = date("d");
                
                if($get_month == $month && $get_year == $year){
                    $report_array = array();
                    for($d=$get_day; $d<=$date; $d++)
                    {
                     $time=mktime(12, 0, 0, $month, $d, $year);
                     if (date('m', $time)==$month){
                          $calender_date = date("Y-m-d", $time );
                          $report_date = date("m/d/Y",$time);
                          
                          $args = array(
            		        "post_type" => "checkin",
            		      //  "author" => $user_id,
            		        "post_status" => "publish",
            		        "fields" =>'ids',
            		        'meta_query' => array(
            		            'relation' => 'AND',
                    		        array(
                                       'key' => 'patient_id',
                                       'value' => $p_id
                                    ),
                                    array(
                                        'key' => 'checkin_status',
                                        'value' => '1'
                                    ),
                                    array(
                                         'key' => 'checkin_date',
                                         'value' => $calender_date
                                    )
            		            )
            		        );
            		        $data = new WP_Query($args);
		                    $checkin_posts = $data->posts;
		                    if(!empty($checkin_posts)){
		                        foreach($checkin_posts as $post_checkin){
		                            $checin_ids =$post_checkin;
	                                $checkin_dates = get_post_meta($checin_ids,"checkin_date",true);
	                                $checkin_times = get_post_meta($checin_ids,"checkin_time",true);
	                                $checkin_time = date("H:i A",strtotime($checkin_times));
                                    $check_date = date("m/d/Y",strtotime($checkin_dates));
                                    $checkin_status = get_post_meta($checin_ids,"checkin_status",true);
                                    $report_array[] = array(
                                       "patient_id" => $p_id,
                                       "name" => $full_name,
                                       "checkin_date" => $check_date,
                                       "checkin_time" => $checkin_time,
                                       "checkin_status" => $checkin_status,
    		                        );
		                        }
		                    }
                        }       
                    }
                    $count_array = count($report_array);
                    $per_page=10;
                    $current_page= $paged;
                    $pagination = ceil($count_array/$per_page);
                    $total_page = array(
                        "total_page" => $pagination,
                        "total_record" => $count_array
                    );
                    return $this->successResponse("Patient report get successfully.",array_slice(array_reverse($report_array),(($current_page-1)*$per_page),$per_page),$total_page);
                }else{
                    $report_array = array();
                    for($d=1; $d<=$date; $d++)
                    {
                     $time=mktime(12, 0, 0, $month, $d, $year);
                     if (date('m', $time)==$month){
                          $calender_date = date("Y-m-d", $time );
                          $report_date = date("m/d/Y",$time);
                          
                          $args = array(
            		        "post_type" => "checkin",
            		      //  "author" => $user_id,
            		        "post_status" => "publish",
            		        "fields" =>'ids',
            		        'meta_query' => array(
            		            'relation' => 'AND',
                    		        array(
                                       'key' => 'patient_id',
                                       'value' => $p_id
                                    ),
                                    array(
                                        'key' => 'checkin_status',
                                        'value' => '1'
                                    ),
                                    array(
                                         'key' => 'checkin_date',
                                         'value' => $calender_date
                                    )
            		            )
            		        );
            		        $data = new WP_Query($args);
		                    $checkin_posts = $data->posts;
		                    if(!empty($checkin_posts)){
		                        foreach($checkin_posts as $post_checkin){
		                            $checin_ids =$post_checkin;
	                                $checkin_dates = get_post_meta($checin_ids,"checkin_date",true);
	                                $checkin_times = get_post_meta($checin_ids,"checkin_time",true);
	                                $checkin_time = date("H:i A",strtotime($checkin_times));
                                    $check_date = date("m/d/Y",strtotime($checkin_dates));
                                    $checkin_status = get_post_meta($checin_ids,"checkin_status",true);
                                    $report_array[] = array(
                                       "patient_id" => $p_id,
                                       "name" => $full_name,
                                       "checkin_date" => $check_date,
                                       "checkin_time" => $checkin_time,
                                       "checkin_status" => $checkin_status,
    		                        );
		                        }
		                    }
                        }       
                    }
                    $count_array = count($report_array);
                    $per_page=10;
                    $current_page= $paged;
                    $pagination = ceil($count_array/$per_page);
                    $total_page = array(
                        "total_page" => $pagination,
                        "total_record" => $count_array
                    );
                    return $this->successResponse("Patient report get successfully.",array_slice(array_reverse($report_array),(($current_page-1)*$per_page),$per_page),$total_page);
                }
            }else{
                $patients = $wpdb->get_results("SELECT * FROM `patient_relations` WHERE `caregiver_id`='$user_id'");
                $checkin_completed = array();
                foreach($patients as $patient){
                    $patient_id = $patient->patient_id;
                    $current_date = date("Y-m-d");
                    $args = array(
                        "post_type" => "checkin",
                        // "author" => $user_id,
                        "post_status" => "publish",
                        "orderby" => "meta_value",
                        "meta_key" => "checkin_date",
                        "order" => "DESC",
                        "posts_per_page" => 1,
                        "meta_query" => array(
                           "relation" => "AND",
                            array(
                                 "key" => "checkin_status",
                                 "value" => "1",
                            ),
                            array(
                                "key" => "patient_id",
                                "value" => $patient_id
                            ),
                            array(
                                "key" => "checkin_date",
                                "value" => $current_date,
                                "compare" => "<=",
                                "type" => "DATE"
                            )
                        ),
                    );
           
                    $data =  new WP_Query($args);
                    $post_data = $data->posts;
                    foreach($post_data as $checkin_data){
                        $checkin_id = $checkin_data->ID;
                        $patient_id = get_post_meta($checkin_id,"patient_id",true);
                        $f_name = get_user_meta($patient_id,"first_name",true);
                        $l_name = get_user_meta($patient_id,"last_name",true);
                        $first_name = isset($f_name)?$f_name:"";
                        $last_name = isset($l_name)?$l_name:"";
                        $full_name = $first_name." ".$last_name;
                        
                        $checkin_date = get_post_meta($checkin_id,"checkin_date",true);
                        $get_date = date("m/d/Y",strtotime($checkin_date));
                        
                        $checkin_time = get_post_meta($checkin_id,"checkin_time",true);
                        $get_time = date("H:i A",strtotime($checkin_time));
                        
                        $checkin_status = get_post_meta($checkin_id,"checkin_status",true);
                      
                        $checkin_completed[]= array(
                            "patient_id"=> $patient_id,
                            "patient_name" => $full_name,
                            "checkin_date" => $get_date,
                            "checkin_time" => $get_time,
                            "checkin_status" => $checkin_status
                        ); 
                    }
                }
                $count_array = count($checkin_completed);
                $per_page=6;
                $current_page= $paged;
                $pagination = ceil($count_array/$per_page);
                $total_page = array(
                    "total_page" => $pagination,
                    "total_record" => $count_array
                );
                return $this->successResponse("Patients get successfully.",array_slice($checkin_completed,(($current_page-1)*$per_page),$per_page),$total_page);
            }
        }
    }
    
    
    public function save_device_detail($request){
        global $wpdb;
        $param              =   $request->get_params();
        $this->isValidToken();
        $user_id            = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $device_token       = $param['device_token'];
        $platform           = $param['platform'];
        $uuid               = $param['uuid'];
        $modal              = $param['modal'];
        
        
        // SELECT `id`, `user_id`, `deviceplatform`, `timezone`, `device_token`, `device_model`, `os_version`, `os_name`, `device_name`, `is_user_logged_in` FROM `wp_users_device_details` WHERE 1
        
// id	user_id	uuid	deviceplatform	device_token	device_model	is_user_logged_in	created_at
        
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }else{
            // $check = $wpdb->get_var("SELECT * FROM `wp_users_device_details` WHERE `user_id`='".$user_id."' AND `uuid`='".$uuid."'");
            // if(!empty($check)){
            //     return $this->successResponse("Device token already exists.");
            // }else{
                $results = $wpdb->get_var( "SELECT COUNT(*) FROM `wp_users_device_details` WHERE `user_id`='".$user_id."' AND `uuid`='$uuid'");
                $numberofcounts = $results;
                if($numberofcounts == 0){

                    $response   = $wpdb->insert('wp_users_device_details',array(
                                                "user_id" => $user_id,
                                                "uuid"  =>$uuid,
                                                "deviceplatform" => $platform,
                                                "device_token" => $device_token,
                                                "device_model" => $modal,
                                                "is_user_logged_in" => "1",
                                            ));
                                            
                    if($response){
                        return $this->successResponse("Device token saved successfully.");
                    }
                    
                }else{
                    $get_row = $wpdb->get_row("SELECT * FROM `wp_users_device_details` WHERE `user_id`='".$user_id."' AND `uuid`='$uuid'");
                    $get_id = $get_row->id;

                    $response = $wpdb->update('wp_users_device_details',array(
                                        "device_token" => $device_token,
                                        "is_user_logged_in" => "1",
                                     ),
                                     array(
                                         "user_id" => $user_id,
                                         "id" => $get_id
                                    ));
                    return $this->successResponse("Device token updated successfully.");
                }
            // }
        }
    }
    
    
    public function remove_device_token($request){
        global $wpdb;
        $param=$request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $uuid = $param['device_uuid'];
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }else{
            $update = $wpdb->delete('wp_users_device_details',
            array(
                "user_id" => $user_id,
                "uuid" => $uuid
            ));
            if($update){
                return $this->successResponse("Device Token Deleted Successfully.");
            }
        }
    }
    
    
    public function get_notification_count($request){
        global $wpdb;
        $param=$request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }else{
            $select = $wpdb->get_results("SELECT * FROM `wp_save_notification` WHERE `receiver_id`='$user_id' AND `read_status`='0' ORDER BY `id` DESC");
            $res_array=array(
               "total_count" => count($select)
            );
             return $this->successResponse('Total notification count get successfully.',$res_array);
        }
    }
    
    
    public function get_my_notifications($request){
        global $wpdb;
        $param=$request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $paged = isset($param['paged']) ? $param['paged'] : 1;
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }else{
            $select = $wpdb->get_results("SELECT * FROM `wp_save_notification` WHERE `receiver_id`='$user_id' ORDER BY `id` DESC");
            $count_select = count($select);
            if($count_select > 0){
                $result_array = array();
                foreach($select as $result){
                    $id = $result->id;
                    $sender_id = $result->sender_id;
                    $fname = get_user_meta($sender_id,"first_name",true);
                    $lname = get_user_meta($sender_id,"last_name",true);
                    $first_name = isset($fname)?$fname:"";
                    $last_name = isset($lname)?$lname:"";
                    $full_name = $first_name." ".$last_name;
                    $title = $result->title;
                    $type = $result->type;
                    $message = $result->notification_msg;
                    $time = $result->created_at;
                    $date_notifications = $result->date;
                    $checkin_status = $result->checkin_status;
                    $p_id = $result->patient_id;
                    $checkin_date = $result->checkin_date;
                    $readStatus = $result->read_status;
                    if($type == "check_in"){
                        $result_array[]=array(
                            "id" => $id,
                            "title" => $title,
                            "type" => $type,
                            "sender_id" => $sender_id,
                            "sender_name" => $full_name,
                            "notification_msg" => $message,
                            "created_at" => $date_notifications,
                            "checkin_status" => $checkin_status,
                            "patient_id"=> $p_id,
                            "read_status" => $readStatus,
                            "checkin_date"=> $checkin_date
                        );
                    }else{
                        $result_array[]=array(
                            "id" => $id,
                            "title" => $title,
                            "type" => $type,
                            "sender_id" => $sender_id,
                            "sender_name" => $full_name,
                            "notification_msg" => $message,
                            "read_status" => $readStatus,
                            "created_at" => $date_notifications,
                        );
                    }
                }
                $per_page=10;
                $current_page= $paged;
                $total_items=count($result_array);
                $pagination = ceil($total_items/$per_page);
                $total_page = array(
                    "total_record" => $total_items,
                    "total_page" => $pagination
                );
                return $this->successResponse('Successfully get notification.',array_slice($result_array,(($current_page-1)*$per_page),$per_page),$total_page);
            }
            else{
                return $this->successResponse('Empty notification.',$result_array);
            }
        }
    }
    
    
    public function get_patients($request){
        global $wpdb;
        $param=$request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $checkin_status = $param['checkin_status'];
        $paged = !empty($param['paged']) ? $param['paged'] : 1;
        
        if($param['view_all'] == "true"){
             $view_all = true;
        }else{
            $view_all = false;
        }
        
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }else{
            $patients = $wpdb->get_results("SELECT * FROM `patient_relations` WHERE `caregiver_id`='$user_id'");
            if(!empty($view_all) && $view_all == true){
                $date = date('Y-m-d');
            }else{
                $date = date('Y-m-d',strtotime("-1 days"));
            }
            $end_date = "2022-01-01";
            
            $s_date = strtotime($date);
            $e_date = strtotime($end_date);
            if($checkin_status == ""){
                $checkin_info = array();
                for ( $i = $s_date; $i > $e_date; $i = $i - 86400){
                    $given_date =  date( 'Y-m-d', $i ); 
                    $g_date = strtotime($given_date);
                    $show_date = date("m/d/Y",strtotime($given_date));
                    foreach($patients as $patient){
                        $patient_id = $patient->patient_id;
                        $user_data = get_userdata($patient_id);
                        $created_at = $user_data->user_registered;
                        $created_date = date("Y-m-d",strtotime($created_at));
                        $create_at = strtotime($created_date);
                        $f_name = get_user_meta($patient_id,"first_name",true);
                        $first_name = isset($f_name)? $f_name :"";
                        $l_name = get_user_meta($patient_id,"last_name",true);
                        $last_name = isset($l_name)? $l_name :"";
                        $full_name = $first_name." ".$last_name;
                        
                        $args = array(
                            "post_type" => "checkin",
                            // "author" => $user_id,
                            "post_status" => "publish",
                            "meta_query" => array(
                            'relation' => 'AND',
                                array(
                                    "key" => "patient_id",
                                    "value" => $patient_id
                                ),
                                array(
                                    "key" => "checkin_date",
                                    "value" => $given_date
                                ),
                            ),
                        );
                        $data =  new WP_Query($args);
                        $post_data = $data->posts;
                        if($create_at <= $g_date){
                            if(empty($post_data)){
                                $checkin_info[] = array(
                                    "patient_id" => $patient_id,
                                    "patient_name" => $full_name,
                                    "checkin_date" => $show_date,
                                    "checkin_time" => "00:00 AM",
                                    "checkin_status" => "0",
                                );
                            }else{
                                foreach($post_data as $checkin_posts){
                                $posts_ID = $checkin_posts->ID;
                                $date_checkin = get_post_meta($posts_ID,"checkin_date",true);
                                $checkin_date = date("m/d/Y",strtotime($date_checkin));
                                $checkin_time = get_post_meta($posts_ID,"checkin_time",true);
                                $time_checkin = date("H:i A",strtotime($checkin_time));
                                $checkin_status = get_post_meta($posts_ID,"checkin_status",true);
                                
                                    $checkin_info[] = array(
                                    "patient_id" => $patient_id,
                                    "patient_name" => $full_name,
                                    "checkin_date" => $checkin_date,
                                    "checkin_time" => $time_checkin,
                                    "checkin_status" => $checkin_status
                                    );
                                }
                            }
                        }
                    }
                }
                $count_array = count($checkin_info);
                $per_page=10;
                $current_page= $paged;
                $pagination = ceil($count_array/$per_page);
                $total_page = array(
                    "total_page" => $pagination,
                    "total_record" => $count_array
                );
                return $this->successResponse("Patients get successfully.",array_slice($checkin_info,(($current_page-1)*$per_page),$per_page),$total_page);
            }elseif($checkin_status == "1" OR $checkin_status == "2"){
                $checkin_info = array();
                for ( $i = $s_date; $i > $e_date; $i = $i - 86400){
                    $given_date =  date( 'Y-m-d', $i ); 
                    $g_date = strtotime($given_date);
                    $show_date = date("m/d/Y",strtotime($given_date));
                    foreach($patients as $patient){
                        $patient_id = $patient->patient_id;
                        $user_data = get_userdata($patient_id);
                        $created_at = $user_data->user_registered;
                        $created_date = date("Y-m-d",strtotime($created_at));
                        $create_at = strtotime($created_date);
                        $f_name = get_user_meta($patient_id,"first_name",true);
                        $first_name = isset($f_name)? $f_name :"";
                        $l_name = get_user_meta($patient_id,"last_name",true);
                        $last_name = isset($l_name)? $l_name :"";
                        $full_name = $first_name." ".$last_name;
                        
                        $args = array(
                            "post_type" => "checkin",
                            // "author" => $user_id,
                            "post_status" => "publish",
                            "orderby" => "meta_value",
                            "meta_key" => "checkin_date",
                            "meta_query" => array(
                            'relation' => 'AND',
                                 array(
                                    "key" => "patient_id",
                                    "value" => $patient_id
                                ),
                                array(
                                     "key" => "checkin_status",
                                     "value" => $checkin_status,
                                ),
                                array(
                                     "key" => "checkin_date",
                                     "value" => $given_date,
                                )
                            ),
                        );
                        $data =  new WP_Query($args);
                        $post_data = $data->posts;
                        if($create_at <= $g_date){
                            foreach($post_data as $checkin_posts){
                                $posts_ID = $checkin_posts->ID;
                                $date_checkin = get_post_meta($posts_ID,"checkin_date",true);
                                $checkin_date = date("m/d/Y",strtotime($date_checkin));
                                $checkin_time = get_post_meta($posts_ID,"checkin_time",true);
                                $time_checkin = date("H:i A",strtotime($checkin_time));
                                $checkin_status = get_post_meta($posts_ID,"checkin_status",true);
                            
                                $checkin_info[] = array(
                                "patient_id" => $patient_id,
                                "patient_name" => $full_name,
                                "checkin_date" => $checkin_date,
                                "checkin_time" => $time_checkin,
                                "checkin_status" => $checkin_status
                                );
                            }
                        }
                    }
                }
                $count_array = count($checkin_info);
                $per_page=10;
                $current_page= $paged;
                $pagination = ceil($count_array/$per_page);
                $total_page = array(
                    "total_page" => $pagination,
                    "total_record" => $count_array
                );
                return $this->successResponse("Patients get successfully.",array_slice($checkin_info,(($current_page-1)*$per_page),$per_page),$total_page);
            }elseif($checkin_status == "0"){
                $checkin_info = array();
                for ( $i = $s_date; $i > $e_date; $i = $i - 86400){
                    $given_date =  date( 'Y-m-d', $i ); 
                    $g_date = strtotime($given_date);
                    $show_date = date("m/d/Y",strtotime($given_date));
                    foreach($patients as $patient){
                        $patient_id = $patient->patient_id;
                        $user_data = get_userdata($patient_id);
                        $created_at = $user_data->user_registered;
                        $created_date = date("Y-m-d",strtotime($created_at));
                        $create_at = strtotime($created_date);
                        $f_name = get_user_meta($patient_id,"first_name",true);
                        $first_name = isset($f_name)? $f_name :"";
                        $l_name = get_user_meta($patient_id,"last_name",true);
                        $last_name = isset($l_name)? $l_name :"";
                        $full_name = $first_name." ".$last_name;
                        
                        $args = array(
                            "post_type" => "checkin",
                            // "author" => $user_id,
                            "post_status" => "publish",
                            "meta_query" => array(
                            'relation' => 'AND',
                                array(
                                    "key" => "patient_id",
                                    "value" => $patient_id
                                ),
                                array(
                                    "key" => "checkin_date",
                                    "value" => $given_date
                                ),
                            ),
                        );
                        $data =  new WP_Query($args);
                        $post_data = $data->posts;
                        if($create_at <= $g_date){
                            if(empty($post_data)){
                                $checkin_info[] = array(
                                    "patient_id" => $patient_id,
                                    "patient_name" => $full_name,
                                    "checkin_date" => $show_date,
                                    "checkin_time" => "00:00 AM",
                                    "checkin_status" => "0",
                                );
                            }
                        }
                    }
                }
                $count_array = count($checkin_info);
                $per_page=10;
                $current_page= $paged;
                $pagination = ceil($count_array/$per_page);
                $total_page = array(
                    "total_page" => $pagination,
                    "total_record" => $count_array
                );
                return $this->successResponse("Patients get successfully.",array_slice($checkin_info,(($current_page-1)*$per_page),$per_page),$total_page);
            }
        }
        
    }
    
    
    
    //Function for checkin questions
    public function checkin_questions($request){
        global $wpdb;
        $param = $request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $patient_id = $param['patient_id'];
        $date = $param['date'];
        $notification_id = $param['notification_id'];
        $read_status = $param['read_status'];
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }
        if(empty($patient_id)){
            return $this->errorResponse('Patient id is required.');
        }else{
            if($read_status == "true"){
                $r_status = true;
            }else{
                $r_status = false;
            }
            $update_notification = $wpdb->update('wp_save_notification',
            array(
                "read_status" => $r_status),
                array(
                    "id" => $notification_id)
            );
            
            $new_date = date("Y-m-d",strtotime($date));
            
            $fname = get_user_meta($patient_id,"first_name",true);
            $first_name = isset($fname)? $fname :"";
            $lname = get_user_meta($patient_id,"last_name",true);
            $last_name = isset($lname)? $lname :"";
            $full_name = $first_name." ".$last_name;
            
            $args = array(
                "post_type" => "checkin",
                // "author" => $user_id,
                "post_status" => "publish",
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => 'patient_id',
                        'value' => $patient_id
                    ),
                    array(
                        'key' => 'checkin_date',
                        'value' => $new_date
                    )
                )
            );
            $data =  new WP_Query($args);
            $post_data = $data->posts;
            
            if(empty($post_data)){
                $args = array(
                "post_type" => "acf-field-group",
                "name" => "group_62cfbb88822ee",
                "post_status" => "publish",
                );
                $data =  new WP_Query($args);
                $postData = $data->posts;  
                $post_id = $postData[0]->ID;
                $fields = acf_get_fields($post_id);
                $quiz_array = array();
                foreach($fields as $key => $field){
                    $quiz_id = $field['ID'];
                    $quiz_quest = $field['label'];
                    $quiz_name = $field['name'];
                    $choices = $field['choices'];
                $choices_array = array();
                foreach($choices as $choice){
                    $choices_array[]= $choice;
                }
                $quiz_array[] = array(
                    "id" => $quiz_id,
                    "question" => $quiz_quest,
                    "options" => $choices_array,
                    );
                }
                $patient_detail = array(
                    "patient_id" => $patient_id,
                    "patient_name" => $full_name
                    );
                  return $this->successResponse("Checkin questions get successfully.", $quiz_array,"",$patient_detail);
            }else{
                $post_key = $post_data[0]->ID;
                $answered_meta = get_post_meta($post_key);
                $checkin_status = get_post_meta($post_key,"checkin_status",true);
        
                $answered_key= array();
                foreach($answered_meta as $key => $val){
                    $answered_key[] = $key;
                }
                
                if($checkin_status == "2"){
                    $args = array(
                        "post_type" => "acf-field-group",
                        "name" => "group_62cfbb88822ee",
                        "post_status" => "publish"
                    );
                    $acf_query =  new WP_Query($args);
                    $acf_Data = $acf_query->posts; 
                    $acf_id = $acf_Data[0]->ID;
                    $acf_fields = acf_get_fields($acf_id);
                    $question_updated_array = array();
                        foreach($acf_fields as $key=>$value){
                            $quiz_id = $value['ID'];
                            $quiz_quest = $value['label'];
                            $quiz_name = $value['name'];
                            $choices = $value['choices'];
                            $choices_array = array();
                            foreach($choices as $choice){
                                $choices_array[]= $choice;
                            }
                            if(! in_array($quiz_name,$answered_key)){
                                $question_updated_array[] = array(
                                    "id" => $quiz_id,
                                    "question" => $quiz_quest,
                                    "options" => $choices_array
                                );
                            }else{
                                foreach($answered_meta as $key => $value){
                                    if($quiz_name == $key){
                                        $question_updated_array[]=array(
                                            "id" =>$quiz_id,
                                            "question" => $quiz_quest,
                                            "answer" => $answered_meta[$key][0],
                                            "options" => $choices_array,
                                            "notes" => $answered_meta[$quiz_id."notes"][0]
                                        );
                                    }
                                }
                            }
                        }
                        $patient_detail = array(
                            "patient_id" => $patient_id,
                            "patient_name" => $full_name
                        );
                        return $this->successResponse("Checkin questions get successfully.", $question_updated_array,"",$patient_detail);
                    }elseif($checkin_status == "1"){
                        
                        $args = array(
                            "post_type" => "acf-field-group",
                            "name" => "group_62cfbb88822ee",
                            "post_status" => "publish"
                        );
                        $acf_query =  new WP_Query($args);
                        $acf_Data = $acf_query->posts; 
                        $acf_id = $acf_Data[0]->ID;
                        $acf_fields = acf_get_fields($acf_id);
                        $answered_checkin_array = array();
                        foreach($acf_fields as $key => $value){
                            $quiz_id = $value['ID'];
                            $question_label = $value['label'];
                            $quiz_name = $value['name'];
                            foreach($answered_meta as $key => $value){
                                if($quiz_name == $key){
                                    $answered_checkin_array[]=array(
                                        "id" =>$quiz_id,
                                        "question" => $question_label,
                                        "answer" => $answered_meta[$key][0],
                                        "notes" => $answered_meta[$quiz_id."notes"][0]
                                    );
                                }
                            }
                            
                        }
                        $patient_detail = array(
                            "patient_id" => $patient_id,
                            "patient_name" => $full_name
                        );
                        return $this->successResponse("Checkin questions and answer get successfully.", $answered_checkin_array,"",$patient_detail);
                }else{
                    return $this->successResponse("Something went wrong.");
                }
            }
        
         }
    }

    
    public function get_user_chat($request){
        global $wpdb;
		$param = $request->get_params();
		$this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        if(empty($user_id)){
            return $this->errorResponse("Please enter valid token");
        }else{
            $sql = "SELECT * FROM `wp_users`
                    INNER JOIN `wp_usermeta` ON wp_users.ID = wp_usermeta.user_id 
                    LEFT JOIN `caregiver_relations` AS caregiver_assign ON wp_usermeta.user_id = caregiver_assign.care_manager_id
                    WHERE wp_usermeta.meta_key = 'wp_capabilities' AND wp_usermeta.meta_value LIKE '%administrator%' OR wp_usermeta.meta_value LIKE '%care_manager%' AND caregiver_assign.caregiver_id= $user_id ";
            $get_users = $wpdb->get_results($sql);
            $user_chat_array = array();
            foreach($get_users as $get_chat){
                $chat_user_id = $get_chat->ID;
                $user_meta = get_userdata($chat_user_id);
                $user_roles = $user_meta->roles[0];
                if($user_roles == "administrator"){
                    $roles_user = "Administrator";
                }elseif($user_roles == "care_manager"){
                    $roles_user = "Care Manager";
                }
                $f_name = get_user_meta($chat_user_id,"first_name",true);
                $l_name = get_user_meta($chat_user_id,"last_name",true);
                $first_name = isset($f_name)?$f_name:"";
                $last_name = isset($l_name)?$l_name:"";
                $full_name = $first_name." ".$last_name;
                $size = 'thumbnail';
                $imgURL = get_wpupa_url($chat_user_id, ['size' => $size]);
                
                $sql = "SELECT * FROM `legacy_messages` WHERE (`receiver_id`='$user_id' AND `sender_id`='$chat_user_id') OR (`receiver_id`='$chat_user_id' AND `sender_id`='$user_id') ORDER BY `id` DESC LIMIT 1";
                $select = $wpdb->get_row($sql);
                
                if(empty($select)){
                    $date = "";
                    $read_status = "";
                }else{
                    $date = $select->date;
                    $read_status = $select->read_status;
                }
                $total_count = $wpdb->get_var("SELECT COUNT(*) FROM `legacy_messages` WHERE `receiver_id`='$user_id' AND `sender_id`='$chat_user_id' AND `read_status`='0'");
                $user_chat_array[]=array(
                    "id"=>$select->id,
                    "message" => stripcslashes($select->messages),
                    "sent" => (int)$select->send_status,
                    "received" => (int)$select->receive_status,
                    "sender_id" => $chat_user_id,
                    "sender_name" => $full_name,
                    "sender_role" => $roles_user,
                    "image" => $imgURL,
                    "time" => $date,
                    "total_count" => $total_count,
                    "read_status" => $read_status
                );
            }
            return $this->successResponse("Get user chat successfully.",$user_chat_array);
        }
    }
    
    
    public function get_user_chats($request){
        global $wpdb;
		$param = $request->get_params();
		$this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        if(empty($user_id)){
            return $this->errorResponse("Please enter valid token");
        }else{
            $sql = "SELECT * FROM `legacy_messages` WHERE `receiver_id`='$user_id' OR `sender_id`='$user_id' ORDER BY `created_at` DESC LIMIT 1";
            $select = $wpdb->get_results($sql);
            
            $args = array(
                "role" =>"administrator"
            );
            $users = get_users($args);
            $admin_id = $users[0]->ID;
            $size = 'thumbnail';
            $imgURL = get_wpupa_url($admin_id, ['size' => $size]);
            
            $user_chat_array = array();
            foreach($select as $select_data){
                $sender_id = $select_data->sender_id;
                $f_name = get_user_meta($sender_id,"first_name",true);
                $l_name = get_user_meta($sender_id,"last_name",true);
                $first_name = isset($f_name)?$f_name:"";
                $last_name = isset($l_name)?$l_name:"";
                $full_name = $first_name." ".$last_name;
                
                $user_chat_array[]=array(
                    "id"=>$select_data->id,
                    "message" => stripcslashes($select_data->messages),
                    "sent" => (int)$select_data->send_status,
                    "received" => (int)$select_data->receive_status,
                    "sender_name" => $full_name,
                    "image" => $imgURL,
                    "time" => $select_data->date
                );
            }
            return $this->successResponse("Get user chat successfully.",$user_chat_array);
        }
    }
    
    
    
    // Function For Get Patient Report
    public function get_patient_report($request){
		global $wpdb;
		$param = $request->get_params();
		$this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
		$id = $param['pid'];
 		$checkin_status = $param['checkin_status'];
 		if($param['view_all'] == "true"){
             $view_all = true;
        }else{
            $view_all = false;
        }
		$paged = !empty($param['paged']) ? $param['paged'] : 1;
		if(empty($user_id)){
		    return $this->errorResponse("Please enter valid token.");
		}else{
		    
		  //  $get_patient = $wpdb->get_row("SELECT * FROM `patient_relations` WHERE `patient_id`='$id'");
		    $user_data = get_userdata($id);
            $get_date = $user_data->user_registered;
		  //  $get_date = $get_patient->created_at; 
		    $get_assign_date = date("Y-m-d",strtotime($get_date));
		    $get_month = date("m",strtotime($get_date));
		    $get_year = date("Y",strtotime($get_date));
		    $get_day = date("d",strtotime($get_date));
		    $fname = get_user_meta($id,"first_name",true);
            $first_name = isset($fname)? $fname :"";
            $lname = get_user_meta($id,"last_name",true);
            $last_name = isset($lname)? $lname :"";
            $full_name = $first_name." ".$last_name;
            
                $month = date("m");
                $year = date("Y");
                $date = date("d");
                $n_date = ($date-1);
                
                
                if($checkin_status == "" OR $checkin_status == "1" OR $checkin_status == "2" OR $checkin_status =="0"){
                    if(!empty($view_all) && $view_all == true){
                        $last_time = date('Y-m-d');
                    }else{
                        $last_time = date('Y-m-d',strtotime("-1 days"));
                    }
                    
                    $last_date = strtotime($last_time);
                    $start_date = strtotime($get_assign_date);
                    
                    if($checkin_status == "0"){
                        $report_array = array();
                        for ( $i = $start_date; $i <= $last_date; $i = $i+86400){
                            $given_date =  date( 'Y-m-d', $i ); 
                            $show_date = date("m/d/Y",strtotime($given_date));
                            $args = array(
                                "post_type" => "checkin",
                                // "author" => $user_id,
                                "post_status" => "publish",
                                "meta_query" => array(
                                'relation' => 'AND',
                                    array(
                                        "key" => "patient_id",
                                        "value" => $id
                                    ),
                                    array(
                                        "key" => "checkin_date",
                                        "value" => $given_date
                                    ),
                                ),
                            );
                            $data = new WP_Query($args);
        	                $checkin_posts = $data->posts;
        	                if(empty($checkin_posts)){
                                $report_array[] = array(
                                    "patient_id" => $id,
                                    "name" => $full_name,
                                    "checkin_date" => $show_date,
                                    "checkin_time" => "00:00 AM",
                                    "checkin_status" => "0",
                                );
        	                }
                        }
                        $per_page=10;
                        $current_page= $paged;
                        $total_items=count($report_array);
                        $pagination = ceil($total_items/$per_page);
                        $total_page = array(
                            "total_record" => $total_items,
                            "total_page" => $pagination
                        );
                        return $this->successResponse("patient report get successfully.",array_slice(array_reverse($report_array),(($current_page-1)*$per_page),$per_page),$total_page);
                    }elseif($checkin_status == "1" OR $checkin_status == "2"){
                        $report_array = array();
                        for ( $i = $start_date; $i <= $last_date; $i = $i+86400){
                            $given_date =  date( 'Y-m-d', $i ); 
                            $show_date = date("m/d/Y",strtotime($given_date));
                            $args = array(
                                "post_type" => "checkin",
                                // "author" => $user_id,
                                "post_status" => "publish",
                                "meta_query" => array(
                                'relation' => 'AND',
                                    array(
                                        "key" => "patient_id",
                                        "value" => $id
                                    ),
                                    array(
                                         "key" => "checkin_date",
                                         "value" => $given_date,
                                    ),
                                    array(
                                        "key" => "checkin_status",
                                        "value" => $checkin_status
                                    ),
                                ),
                            );
                            $data = new WP_Query($args);
        	                $checkin_posts = $data->posts;
        	                
        	                foreach($checkin_posts as $datas){
        	                    $post_id = $datas->ID;
        	                    $checkin_date = get_post_meta($post_id,"checkin_date",true);
        	                    $checkin_time = get_post_meta($post_id,"checkin_time",true);
        	                    $checkin_status = get_post_meta($post_id,"checkin_status",true);
        	                    $report_array[] = array(
                                    "patient_id" => $id,
                                    "name" => $full_name,
                                    "checkin_date" => date("m/d/Y",strtotime($checkin_date)),
                                    "checkin_time" => date("H:i A",strtotime($checkin_time)),
                                    "checkin_status" => $checkin_status,
                                );
        	                }
                        }
                        $per_page=10;
                        $current_page= $paged;
                        $total_items=count($report_array);
                        $pagination = ceil($total_items/$per_page);
                        $total_page = array(
                            "total_record" => $total_items,
                            "total_page" => $pagination
                        );
                        return $this->successResponse("patient report get successfully.",array_slice(array_reverse($report_array),(($current_page-1)*$per_page),$per_page),$total_page);
                    }else{
                        $report_array = array();
                        for ( $i = $start_date; $i <= $last_date; $i = $i+86400){
                            $given_date =  date( 'Y-m-d', $i ); 
                            $show_date = date("m/d/Y",strtotime($given_date));
                            $args = array(
                                "post_type" => "checkin",
                                // "author" => $user_id,
                                "post_status" => "publish",
                                "meta_query" => array(
                                'relation' => 'AND',
                                    array(
                                        "key" => "patient_id",
                                        "value" => $id
                                    ),
                                    array(
                                        "key" => "checkin_date",
                                        "value" => $given_date
                                    ),
                                ),
                            );
                            $data = new WP_Query($args);
        	                $checkin_posts = $data->posts;
        	                if(empty($checkin_posts)){
        	                    $report_array[] = array(
                                    "patient_id" => $id,
                                    "name" => $full_name,
                                    "checkin_date" => $show_date,
                                    "checkin_time" => "00:00 AM",
                                    "checkin_status" => "0",
                                );
        	                }else{
                                foreach($checkin_posts as $datas){
                                $post_id = $datas->ID;
                                $checkin_date = get_post_meta($post_id,"checkin_date",true);
                                $checkin_time = get_post_meta($post_id,"checkin_time",true);
                                $checkin_status = get_post_meta($post_id,"checkin_status",true);
                                $report_array[] = array(
                                    // "post_id" => $post_id,
                                    "patient_id" => $id,
                                    "name" => $full_name,
                                    "checkin_date" => date("m/d/Y",strtotime($checkin_date)),
                                    "checkin_time" => date("H:i A",strtotime($checkin_time)),
                                    "checkin_status" => $checkin_status,
                                    );
                                }
        	                }
                        }
                        $per_page=10;
                        $current_page= $paged;
                        $total_items=count($report_array);
                        $pagination = ceil($total_items/$per_page);
                        $total_page = array(
                            "total_record" => $total_items,
                            "total_page" => $pagination
                        );
                        return $this->successResponse("patient report get successfully.",array_slice(array_reverse($report_array),(($current_page-1)*$per_page),$per_page),$total_page);
                    }
                }
            }
		}
		
		
	public function get_calender_schedule($request){
	  global $wpdb;
      $param = $request->get_params();
      $this->isValidToken();
      $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
      $date = $param['date'];
      $paged = !empty($param['paged']) ? $param['paged'] : 1;
      if(empty($user_id)){
          return $this->errorResponse('Please enter valid token.');
      }else{
            $month = date("m",strtotime($date));
            $year = date("Y",strtotime($date));
            $numberOfDays =cal_days_in_month(CAL_GREGORIAN,$month, $year);
            $current_month = date("m");
            $current_year = date("Y");
            $current_day = date("d");
            $patients = $wpdb->get_results("SELECT * FROM `patient_relations` WHERE `caregiver_id`='$user_id'");
            
            if($month == $current_month && $year == $current_year){
                $checkin_array = array();
                for($d=1; $d<=$current_day; $d++){
                    $time=mktime(12, 0, 0, $month, $d, $year);
                    if (date('m', $time)==$month){
                        $new_date = date("Y-m-d", $time );
                        $s_date = strtotime($new_date);
                        $checkin_date = date("m/d/Y",$time);
                        foreach($patients as $patient){
                            $patient_ids = $patient->patient_id;
                            $patient_data = get_userdata($patient_ids);
                            $patient_registerd_date = $patient_data->user_registered;
                            $end_date = date("Y-n-d",strtotime($patient_registerd_date));
                            $e_date = strtotime($end_date);
                            $f_name = get_user_meta($patient_ids,"first_name",true);
                            $first_name = !empty($f_name)? $f_name :"";
                            $l_name = get_user_meta($patient_ids,"last_name",true);
                            $last_name = !empty($l_name)? $l_name :"";
                            $full_name = $first_name." ".$last_name;
                            $args = array(
                               "post_type" => "checkin",
                            //   "author" => $user_id,
                               "post_status" => "publish",
                               'meta_query' => array(
                                   'relation' => 'AND',
                                   array(
                                       'key' => 'patient_id',
                                       'value' => $patient_ids
                                   ),
                                   array(
                                       'key' => 'checkin_date',
                                       'value' => $new_date
                                   )
                               )
                            );
                            $data =  new WP_Query($args);
                            $post_data = $data->posts;
                            if($s_date >= $e_date){
                                if(empty($post_data)){
                                    $checkin_array[] = array(
                                        // "post_is"=>$post_id,
                                        "patient_id" => $patient_ids,
                                        "patient_name" => $full_name,
                                        "checkin_status" => "0",
                                        "checkin_time" => "00:00 AM",
                                        "checkin_date" => $checkin_date
                                    );
                                }else{
                                    foreach($post_data as $checkin_post){
                                        $post_id = $checkin_post->ID;
                                        $checkin_dates = get_post_meta($post_id,"checkin_date",true);
                                        $check_date = date("m/d/Y",strtotime($checkin_dates));
                                        $time_checkin = get_post_meta($post_id,"checkin_time",true);
                                        $checkin_time = date("H:i A",strtotime($time_checkin));
                                        $checkin_status = get_post_meta($post_id,"checkin_status",true);
                                        if($new_date == $checkin_dates){
                                                $checkin_array[] = array(
                                                "patient_id" => $patient_ids,
                                                "patient_name" => $full_name,
                                                "checkin_status" => $checkin_status,
                                                "checkin_time" => $checkin_time,
                                                "checkin_date" => $check_date
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $per_page=10;
                $current_page= $paged;
                $total_items=count($checkin_array);
                $pagination = ceil($total_items/$per_page);
                $total_page = array(
                    "total_page" => $pagination,
                    "total_record" => $total_items
                );
                return $this->successResponse("Petients checkin by month get successfully.",array_slice(array_reverse($checkin_array),(($current_page-1)*$per_page),$per_page),$total_page);
    	    }elseif($month < $current_month && $year == $current_year){
    	        $checkin_array = array();
                for($d=1; $d<=$current_day; $d++){
                    $time=mktime(12, 0, 0, $month, $d, $year);
                    if (date('m', $time)==$month){
                        $new_date = date("Y-m-d", $time );
                        $s_date = strtotime($new_date);
                        $checkin_date = date("m/d/Y",$time);
                        foreach($patients as $patient){
                            $patient_ids = $patient->patient_id;
                            $patient_data = get_userdata($patient_ids);
                            $patient_registerd_date = $patient_data->user_registered;
                            $end_date = date("Y-n-d",strtotime($patient_registerd_date));
                            $e_date = strtotime($end_date);
                            $f_name = get_user_meta($patient_ids,"first_name",true);
                            $first_name = !empty($f_name)? $f_name :"";
                            $l_name = get_user_meta($patient_ids,"last_name",true);
                            $last_name = !empty($l_name)? $l_name :"";
                            $full_name = $first_name." ".$last_name;
                            $args = array(
                               "post_type" => "checkin",
                            //   "author" => $user_id,
                               "post_status" => "publish",
                               'meta_query' => array(
                                   'relation' => 'AND',
                                   array(
                                       'key' => 'patient_id',
                                       'value' => $patient_ids
                                   ),
                                   array(
                                       'key' => 'checkin_date',
                                       'value' => $new_date
                                   )
                               )
                            );
                            $data =  new WP_Query($args);
                            $post_data = $data->posts;
                            if($s_date >= $e_date){
                                if(empty($post_data)){
                                    $checkin_array[] = array(
                                        // "post_is"=>$post_id,
                                        "patient_id" => $patient_ids,
                                        "patient_name" => $full_name,
                                        "checkin_status" => "0",
                                        "checkin_time" => "00:00 AM",
                                        "checkin_date" => $checkin_date
                                    );
                                }else{
                                    foreach($post_data as $checkin_post){
                                        $post_id = $checkin_post->ID;
                                        $checkin_dates = get_post_meta($post_id,"checkin_date",true);
                                        $check_date = date("m/d/Y",strtotime($checkin_dates));
                                        $time_checkin = get_post_meta($post_id,"checkin_time",true);
                                        $checkin_time = date("H:i A",strtotime($time_checkin));
                                        $checkin_status = get_post_meta($post_id,"checkin_status",true);
                                        if($new_date == $checkin_dates){
                                                $checkin_array[] = array(
                                                "patient_id" => $patient_ids,
                                                "patient_name" => $full_name,
                                                "checkin_status" => $checkin_status,
                                                "checkin_time" => $checkin_time,
                                                "checkin_date" => $check_date
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $per_page=10;
                $current_page= $paged;
                $total_items=count($checkin_array);
                $pagination = ceil($total_items/$per_page);
                $total_page = array(
                    "total_page" => $pagination,
                    "total_record" => $total_items
                );
                return $this->successResponse("Petients checkin by month get successfully.",array_slice(array_reverse($checkin_array),(($current_page-1)*$per_page),$per_page),$total_page);
    	    }elseif($year <= $current_year){
    	        $checkin_array = array();
                for($d=1; $d<=$current_day; $d++){
                    $time=mktime(12, 0, 0, $month, $d, $year);
                    if (date('m', $time)==$month){
                        $new_date = date("Y-m-d", $time );
                        $s_date = strtotime($new_date);
                        $checkin_date = date("m/d/Y",$time);
                        foreach($patients as $patient){
                            $patient_ids = $patient->patient_id;
                            $patient_data = get_userdata($patient_ids);
                            $patient_registerd_date = $patient_data->user_registered;
                            $end_date = date("Y-n-d",strtotime($patient_registerd_date));
                            $e_date = strtotime($end_date);
                            $f_name = get_user_meta($patient_ids,"first_name",true);
                            $first_name = !empty($f_name)? $f_name :"";
                            $l_name = get_user_meta($patient_ids,"last_name",true);
                            $last_name = !empty($l_name)? $l_name :"";
                            $full_name = $first_name." ".$last_name;
                            $args = array(
                               "post_type" => "checkin",
                            //   "author" => $user_id,
                               "post_status" => "publish",
                               'meta_query' => array(
                                   'relation' => 'AND',
                                   array(
                                       'key' => 'patient_id',
                                       'value' => $patient_ids
                                   ),
                                   array(
                                       'key' => 'checkin_date',
                                       'value' => $new_date
                                   )
                               )
                            );
                            $data =  new WP_Query($args);
                            $post_data = $data->posts;
                            if($s_date >= $e_date){
                                if(empty($post_data)){
                                    $checkin_array[] = array(
                                        // "post_is"=>$post_id,
                                        "patient_id" => $patient_ids,
                                        "patient_name" => $full_name,
                                        "checkin_status" => "0",
                                        "checkin_time" => "00:00 AM",
                                        "checkin_date" => $checkin_date
                                    );
                                }else{
                                    foreach($post_data as $checkin_post){
                                        $post_id = $checkin_post->ID;
                                        $checkin_dates = get_post_meta($post_id,"checkin_date",true);
                                        $check_date = date("m/d/Y",strtotime($checkin_dates));
                                        $time_checkin = get_post_meta($post_id,"checkin_time",true);
                                        $checkin_time = date("H:i A",strtotime($time_checkin));
                                        $checkin_status = get_post_meta($post_id,"checkin_status",true);
                                        if($new_date == $checkin_dates){
                                                $checkin_array[] = array(
                                                "patient_id" => $patient_ids,
                                                "patient_name" => $full_name,
                                                "checkin_status" => $checkin_status,
                                                "checkin_time" => $checkin_time,
                                                "checkin_date" => $check_date
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $per_page=10;
                $current_page= $paged;
                $total_items=count($checkin_array);
                $pagination = ceil($total_items/$per_page);
                $total_page = array(
                    "total_page" => $pagination,
                    "total_record" => $total_items
                );
                return $this->successResponse("Petients checkin by month get successfully.",array_slice(array_reverse($checkin_array),(($current_page-1)*$per_page),$per_page),$total_page);
    	    }else{
    	        return $this->errorResponse("Something went wrong.");
    	    }
    	}
	}

	
	public function get_calender_checkin($request){
	  global $wpdb;
      $param = $request->get_params();
      $this->isValidToken();
      $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
      $date = $param['date'];
      if(empty($user_id)){
          return $this->errorResponse('Please enter valid token.');
      }else{
            $month = date("m",strtotime($date));
            $year = date("Y",strtotime($date));
            $numberOfDays =cal_days_in_month(CAL_GREGORIAN,$month, $year);
            
            $current_month = date("m");
            $current_year = date("Y");
            $current_day = date("d");
            $list = array();
            if($month == $current_month && $year == $current_year){
                for($d=1; $d<=$current_day; $d++){
                    $time=mktime(12, 0, 0, $month, $d, $year);
                    if (date('m', $time)==$month){
                        $list[]=array(
                            "day" => date('d', $time),
                            "date" => date("m/d/Y", $time )
                        );
                    }
                }
            }else{
                for($d=1; $d<=$numberOfDays; $d++){
                    $time=mktime(12, 0, 0, $month, $d, $year);
                        if (date('m', $time)==$month){
                        $list[]=array(
                            "day" => date('d', $time),
                            "date" => date("m/d/Y", $time )
                        );
                    }
                }
            }

            $checkin_array = array();
            $patients = $wpdb->get_results("SELECT * FROM `patient_relations` WHERE `caregiver_id`='$user_id'");
            foreach($patients as $care_patients){
                $patient_ids = $care_patients->patient_id;
                $calender_array = array();
                foreach($list as $list_array){
                    $date_format = $list_array['date'];
                    $new_date = date("Y-m-d",strtotime($date_format));
                    $calender_date = date("m/d/Y",strtotime($date_format));
                    $args = array(
                       "post_type" => "checkin",
                    //   "author" => $user_id,
                       "post_status" => "publish",
                       'meta_query' => array(
                           'relation' => 'AND',
                           array(
                               'key' => 'patient_id',
                               'value' => $patient_ids
                           ),
                           array(
                               'key' => 'checkin_date',
                               'value' => $new_date
                           )
                       )
                    );
                    $data =  new WP_Query($args);
                    $post_data = $data->posts;
                    if(count($post_data) == 0){
                       $calender_array[]=array(
                           "checkin_status" => "0",
                           "checkin_time" => "00:00 AM",
                           "checkin_date" => $calender_date
                       );
                   }else{
                       foreach($post_data as $checkin_post){
                            $post_id = $checkin_post->ID;
                            $checkin_dates = get_post_meta($post_id,"checkin_date",true);
                            $check_date = date("m/d/Y",strtotime($checkin_dates));
                            $time_checkin = get_post_meta($post_id,"checkin_time",true);
                            $checkin_time = date("H:i A",strtotime($time_checkin));
                            $checkin_status = get_post_meta($post_id,"checkin_status",true);
                            if($new_date == $checkin_dates){
                                $calender_array[] = array(
                                    // "post_is"=>$post_id,
                                    "checkin_status" => $checkin_status,
                                    "checkin_time" => $checkin_time,
                                    "checkin_date" => $check_date
                                );
                            }
                        }   
                   }
                }
                $f_name = get_user_meta($patient_ids,"first_name",true);
                $first_name = !empty($f_name)? $f_name :"";
                $l_name = get_user_meta($patient_ids,"last_name",true);
                $last_name = !empty($l_name)? $l_name :"";
                $full_name = $first_name." ".$last_name;
                $checkin_array[] = array(
                     "patient_id" => $patient_ids,
                     "patient_name" => $full_name,
                     "patient_info" => $calender_array
                );
            }
            
            return $this->successResponse("Petients checkin by month get successfully.",$checkin_array);
	    }
	}
	
	public function support_chat($request){
	    global $wpdb;
        $param = $request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $receiver_id = $param['receiver_id'];
        $messages = trim($param['message']);
        $sent = $param['sent'];
        $receive = $param['received'];
        if(empty($user_id)){
            return $this->errorResponse('Please enter valid token.');
        }else{
            if(empty($messages)){
                return $this->errorResponse('Empty messages.');
            }else{
                $f_name = get_user_meta($user_id,"first_name",true);
                $first_name = !empty($f_name)? $f_name :"";
                $l_name = get_user_meta($user_id,"last_name",true);
                $last_name = !empty($l_name)? $l_name :"";
                $full_name = $first_name." ".$last_name;
                
                $user_meta = get_userdata($user_id);
                $caregiver_email = $user_meta->user_email;
                $date = date("Y-m-d H:i:s");
                $times = date("H:i:s",strtotime($date));
                $args = array(
                    "role" => "administrator",
                );
                $get_users = get_users($args);
                $adminEmail = $get_users[0]->user_email;
                
                if($sent == true){
                    $sent_status = 1;
                }else{
                    $sent_status = 0;
                }
                
                if($receive == false){
                    $receive_status = 0;
                }else{
                    $receive_status = 1;
                }
                
                $headers = array('Content-Type: text/html; charset=UTF-8');
                $subject = "Message from"." ".$full_name.".";
                $insert = $wpdb->insert('legacy_messages',array(
                      "sender_id" => $user_id,
                      "receiver_id" => $receiver_id,
                      "messages" => $messages,
                      "time" => $times,
                      "date" => $date,
                      "send_status" => $sent_status,
                      "receive_status" => $receive_status
                ));
                
                if($insert){
                     $sent = wp_mail($adminEmail , $subject, $messages, $headers);
                     $data[] = array(
                    "message" => $messages,
                    "sent" => $sent_status,
                    "received" => $receive_status,
                    "timeago" => $date
                );
                return $this->successResponse('We have received your message successfully.',$data);
                }
            }
        }
	}
	
	
	public function clear_notification($request){
	    global $wpdb;
        $param = $request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $notification_id = $param['notification_id'];
        if(empty($user_id)){
           return $this->errorResponse('Please enter valid token.');
        }else{
            if(isset($notification_id)){
                $delete = $wpdb->query("DELETE FROM `wp_save_notification` WHERE `receiver_id`='$user_id' AND `id`='$notification_id'");
                if($delete){
                     return $this->successResponse('This Notification deleted successfully.');
                }
            }else{
                $delete = $wpdb->query("DELETE FROM `wp_save_notification` WHERE `receiver_id`='$user_id'");
                if($delete){
                    return $this->successResponse('All notification deleted successfully.');
                }
            }
        }
    }
	
	
	public function get_support_chat($request){
	    global $wpdb;
        $param = $request->get_params();
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $notification_id = $param['notification_id'];
        if($param['read_status'] == "true"){
            $read_status = true;
        }else{
            $read_status = false;
        }
        $sender_id = $param['sender_id'];
        if(empty($user_id)){
           return $this->errorResponse('Please enter valid token.');
        }else{
            $wpdb->update('legacy_messages',
                array(
                    "read_status" => 1,
                ),
                array(
                    "sender_id" => $sender_id,
                    "receiver_id" => $user_id
                )
            );
            
            if(!empty($notification_id)){
                $wpdb->update('wp_save_notification',
                    array(
                        "read_status" => $read_status
                    ),
                    array(
                        "id" => $notification_id,
                    )
                );
            }
            
            $args = array(
                "role" =>"administrator"
            );
            
            $f_name = get_user_meta($sender_id,"first_name",true);
            $l_name = get_user_meta($sender_id,"last_name",true);
            $first_name = isset($f_name)?$f_name:"";
            $last_name =isset($l_name)?$l_name:"";
            $full_name = $first_name." ".$last_name;
            $chat_with= array(
                "id" => $sender_id,
                "name" => $full_name,
            );
            
            $size = 'thumbnail';
            $imgURL = get_wpupa_url($sender_id, ['size' => $size]);
            $sql = "SELECT * FROM `legacy_messages` WHERE (`sender_id`='$user_id' AND `receiver_id`='$sender_id') OR (`sender_id`='$sender_id' AND `receiver_id`='$user_id') ORDER BY `created_at` ASC ";
            $fetch = $wpdb->get_results($sql);
            $user_chat_array = array();
            foreach($fetch as $datas){
                $receiver_id = $datas->receiver_id;
                $sender_id = $datas->sender_id;
                $user_chat_array[]=array(
                    // "id"=>$select_data->id,
                    "message" => stripcslashes($datas->messages),
                    "timeago" => $datas->date,
                    "image" => $imgURL,
                    "sent" => (int)$datas->send_status,
                    "received" => (int)$datas->receive_status
                );  
            }
            return $this->successResponse('Messages get successfully.',$user_chat_array,"","",$chat_with); 
        }
	}
		

	//Function for checkin form submit
	public function checkin_submit($request){
       global $wpdb;
       $param = $request->get_params();
       $this->isValidToken();
       $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
       $id = $param['patient_id'];
       $question_id = $param['data'];
       $date = $param['date'];
       $notification_id = $param['notification_id'];
       
       if(empty($user_id)){
           return $this->errorResponse('Please enter valid token.');
        }else{
            $new_format = strtotime($date);
            $current_time = time();
            $time = date("H:i:s",$current_time);
            $new_date = date("Y-m-d",$new_format);
            $new_month = date("m",$new_format);
            $new_year = date("Y",$new_format);
            $current_month = date("m");
            $current_year = date("Y");
            
            $args = array(
                "post_type" => "checkin",
                // "author" => $user_id,
                "post_status" => "publish",
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => 'patient_id',
                        'value' => $id
                    ),
                    array(
                        'key' => 'checkin_date',
                        'value' => $new_date
                    )
                )
            );
            $data =  new WP_Query($args);
            $post_data = $data->posts;
            $checkin_array = array();
            foreach($post_data as $post_datas){
                $ids = $post_datas->ID;
                $checkin_dates = get_post_meta($ids,"checkin_date",true);
                $get_day = date("d",strtotime($checkin_dates));
                $get_months = date("m",strtotime($checkin_dates));
                $checkin_status = get_post_meta($ids,"checkin_status",true);
                $checkin_array[] = array(
                  "post_id" => $ids,
                  "checkin_date" => $checkin_dates,
                  "checkin_day" => $get_day,
                  "checkin_months" => $get_months,
                  "checkin_status" =>$checkin_status
                );
            }
            if(count($checkin_array) == 0 && !($new_month > $current_month) && !($new_year > $current_year)){
                if(count($question_id) == 0){
                   return $this->errorResponse('You need to answer atleast one question.');
                }else{
                    $caregiver_f_name = get_user_meta($user_id,"first_name",true);
                    $caregiver_first_name = isset($caregiver_f_name)? $caregiver_f_name :"";
                    $caregiver_l_name = get_user_meta($user_id,"last_name",true);
                    $caregiver_last_name = isset($caregiver_l_name)? $caregiver_l_name :"";
                    $caregiver_full_name = $caregiver_first_name." ".$caregiver_last_name;
                    
                    $patient_f_name = get_user_meta($id,"first_name",true);
                    $patient_first_name = isset($patient_f_name)? $patient_f_name :"";
                    $patient_l_name = get_user_meta($id,"last_name",true);
                    $patient_last_name = isset($patient_l_name)? $patient_l_name :"";
                    $patient_full_name = $patient_first_name." ".$patient_last_name;
                    
                    $post_title = $caregiver_full_name."-".$patient_full_name."-".$date;
                    
                    $args = array(
                        "post_type" => "acf-field-group",
                        "name" => "group_62cfbb88822ee",
                        "post_status" => "publish"
                    );
                    $data =  new WP_Query($args);
                    $postData = $data->posts;  
                    $post_id = $postData[0]->ID;
                    $fields = acf_get_fields($post_id);
                    $question_ids = array();
                    $answered_id = array();
                    $args = array(
                        "post_title"=>$post_title,
                        "post_status" =>"publish",
                        "post_type"=>"checkin"
                    );
                    $posts_id =  wp_insert_post($args);
                    update_post_meta($posts_id,"patient_id",$id);
                    foreach($fields as $field){
                        $question_ids[] =  $field['ID'];
                        foreach($question_id as $key => $value){
                            $answers = $value['answers'];
                            $notes = trim($value['notes']);
                            if($field['ID'] == $value['id']){
                                if(!empty($answers)){
                                    update_post_meta($posts_id,$field['name'],$value['answers']);
                                    $answered_id[] = $field['ID'];
                                }
                                if(!empty($notes)){
                                    update_post_meta($posts_id,$field['ID']."notes",$notes);
                                }
                            }
                        }
                    }
                    update_post_meta($posts_id,"checkin_date",$new_date);
                    update_post_meta($posts_id,"checkin_time",$time);
                    $total_question = count($question_ids);
                    update_post_meta($posts_id,"total_question",$total_question);
                    $answered_count = count($answered_id);
                    update_post_meta($posts_id,"answered_count",$answered_count);
                    $empty_count = ($total_question - $answered_count);
                    update_post_meta($posts_id,"empty_count",$empty_count);
                    if($total_question == $answered_count){
                        update_post_meta($posts_id,"checkin_status","1");
                        if(!empty($notification_id)){
                            $wpdb->update('wp_save_notification',
                                array(
                                'post_id' => $posts_id,
                                'checkin_status' => 1 
                                ),
                                array(
                                    'id' => $notification_id,
                                )
                            );
                        }
                    }elseif($answered_count < $total_question && $answered_count != 0 ){
                        update_post_meta($posts_id,"checkin_status","2");
                        if(!empty($notification_id)){
                            $wpdb->update('wp_save_notification',
                                array(
                                'post_id' => $posts_id,
                                'checkin_status' => 2
                                ),
                                array(
                                    'id' => $notification_id,
                                )
                            );
                        }
                    }
                   $get_status = get_post_meta($posts_id,"checkin_status",true);
                    $this->sendPushServer($user_id,"check_in","Check In Submitted.","Check In",$user_id,$posts_id,$get_status,$id,$date);
                    
                    return $this->successResponse("Your checkin have been submited successfully.");
                }
            }else{
                foreach($checkin_array as $array_checkin){
                    $updated_post_id = $array_checkin['post_id'];
                    $updated_checkin_status = $array_checkin['checkin_status'];
                    if($updated_checkin_status == "1"){
                         return $this->successResponse("Your checkin have been already submited for this date.");
                    }else{
                        $args = array(
                            "post_type" => "acf-field-group",
                            "name" => "group_62cfbb88822ee",
                            "post_status" => "publish"
                        );
                        $data =  new WP_Query($args);
                        $postData = $data->posts;  
                        $post_id = $postData[0]->ID;
                        $fields = acf_get_fields($post_id);
                        $checkin_answer_count = get_post_meta($updated_post_id,"answered_count",true);
                        $count_updated_data = array();
                        $count_total_question = array();
                        foreach($fields as $field){
                            $count_total_question[]= $field['ID']; 
                            $question_ids = $field['ID'];
                            foreach($question_id as $quest_update){
                                $notes = trim($quest_update['notes']);
                                $quest_upd_id = $quest_update['id'];
                                if($quest_upd_id == $question_ids){
                                    $quest_name = $field['name'];
                                    $quest_answers = $quest_update['answers'];
                                    $get_post_update = get_post_meta($updated_post_id,$quest_name,true);
                                    if($get_post_update){
                                        if(!empty($quest_answers)){
                                            update_post_meta($updated_post_id,$quest_name,$quest_answers);
                                        }
                                        if(!empty($notes)){
                                            update_post_meta($updated_post_id,$quest_upd_id."notes",$notes);
                                        }
                                    }else{
                                        if(!empty($quest_answers)){
                                            update_post_meta($updated_post_id,$quest_name,$quest_update['answers']);
                                            $count_updated_data[]= $quest_update['answer']; 
                                        }
                                        if(!empty($notes)){
                                            update_post_meta($updated_post_id,$quest_upd_id."notes",$notes);   
                                        }
                                    }
                                }
                            }
                        }
                        $count_total_questions = count($count_total_question);
                        update_post_meta($updated_post_id,"total_question",$count_total_questions);
                        $count_total_answers = $checkin_answer_count + count($count_updated_data);
                        update_post_meta($updated_post_id,"answered_count",$count_total_answers);
                        $total_empty_count = $count_total_questions - $count_total_answers;
                        update_post_meta($updated_post_id,"empty_count",$total_empty_count);
                        if($count_total_questions == $count_total_answers){
                            update_post_meta($updated_post_id,"checkin_status","1");
                            if(!empty($notification_id)){
                                $wpdb->update('wp_save_notification',
                                    array(
                                    'post_id' => $posts_id,
                                    'checkin_status' => 1 
                                    ),
                                    array(
                                        'id' => $notification_id,
                                    )
                                );
                            }
                        }elseif($count_total_answers < $count_total_questions && $count_total_answers != 0 ){
                            update_post_meta($updated_post_id,"checkin_status","2");
                            if(!empty($notification_id)){
                                $wpdb->update('wp_save_notification',
                                    array(
                                    'post_id' => $posts_id,
                                    'checkin_status' => 2 
                                    ),
                                    array(
                                        'id' => $notification_id,
                                    )
                                );
                            }
                        }
                        $get_status = get_post_meta($updated_post_id,"checkin_status",true);
                        $this->sendPushServer($user_id,"check_in","Check In Submitted.","Check In",$user_id,$updated_post_id,$get_status,$id,$date);
                        
                        return $this->successResponse("Answered submitted successfully.");
                    }
                }
            }
        }
    }
	
	
	
	
    public function get_patients_by_date($request){
        global $wpdb;
        $param = $request->get_params(); 
        $this->isValidToken();
        $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
        $date = $param['date'];
        
        if(empty($user_id)){
           return $this->errorResponse('Please enter valid token.');
        }else{
            if(empty($date)){
               return $this->errorResponse('Empty date.');
            }else{
                $patients = $wpdb->get_results("SELECT * FROM `patient_relations` WHERE `caregiver_id`='$user_id'");
                
                $new_format = strtotime($date);
                $new_date = date("Y-m-d",$new_format);
                $new_date_empty = date("m/d/Y",$new_format);
                
                $patient_data = array();
                foreach($patients as $patient){
                    $p_id =$patient->patient_id;
                    $f_name = get_user_meta($p_id,"first_name",true);
                    $first_name = !empty($f_name)? $f_name :"";
                    $l_name = get_user_meta($p_id,"last_name",true);
                    $last_name = !empty($l_name)? $l_name :"";
                    $full_name = $first_name." ".$last_name;
                    $args = array(
                        "post_type" => "checkin",
                        // "author" => $user_id,
                        "post_status" => "publish",
                        'meta_query' => array(
                            "relation" => "AND",
                            array(
                                "key" => 'patient_id',
                                "value" => $p_id
                            ),
                            array(
                                'key' => 'checkin_date',
                                'value' => $new_date
                            )
                        )
                    );
                    $data =  new WP_Query($args);
                    $post_data = $data->posts;
                    if(empty($post_data)){
                        $patient_data[] = array(
                            "id" => $p_id,
                            "name" => $full_name,
                            "date" => $new_date_empty,
                            "time" => "00:00 AM",
                            "status" => "0"
                        );
                    }else{
                        foreach($post_data as $datas){
                            $post_ID = $datas->ID;
                            $checkin_date = get_post_meta($post_ID,"checkin_date",true);
                            $get_date = date("m/d/Y",strtotime($checkin_date));
                            $checkin_time = get_post_meta($post_ID,"checkin_time",true);
                            $get_time = date("H:i A",strtotime($checkin_time));
                            $checkin_status = get_post_meta($post_ID,"checkin_status",true);
                            $patient_data[] = array(
                                "id" => $p_id,
                                "name" => $full_name,
                                "date" => $get_date,
                                "time" => $get_time,
                                "status" => $checkin_status
                            );
                        }
                    }
                }
                return $this->successResponse('Patients by date get successfully.',$patient_data);
            }
        }
    }
    
    public function delete_account_request($request){
        global $wpdb;
        $param          = $request->get_params();
        $this->isValidToken();
        $user_id        = !empty($this->user_id)?$this->user_id:$param['token'];
        // echo $user_id;
        // die;
        $message        = trim($param['message']);
        if($user_id){
            
            $db_data = array(
                'user_id' => $user_id,
                'message' => $message
                );
            //   print_r($param);
            //   die;
            if($wpdb->insert('delete_account_request',$db_data)){
                
                update_user_meta($user_id,'delete_account','yes');
                $mail = $this->goToMailAdminForAccountDeleteion($user_id,$message);
                return $this->successResponse("Your account is suspended. We are processing to delete your account permanently.");
            }else{
                return $this->errorResponse('Something went wrong! Please login again.');
            }
            
        }else{
           return $this->errorResponse('Please enter valid token.');
        }
    }
    
    public function goToMailAdminForAccountDeleteion($user_id,$message){
        $headers = array(
           'From: no-reply@knoxweb.com',
     	   'Content-Type: text/html; charset=UTF-8'
        );
        $subject = 'Delete Account Request';
        $name           = get_user_meta($user_id, 'first_name', true) . " " . get_user_meta($user_id, 'last_name', true);
        $full_name      = ucwords($name);
        $admin_email = get_option('admin_email'); 
    
        // $to =  $admin_email;
        
        $to = 'jamtechapp@gmail.com';
        
        $body_content = '<div style="max-width: 700px;width: 100%;margin: 0px auto;">
                        	<table style="width: 100%; border-spacing: 0px; box-shadow: 0px 0px 5px #c3c3c3 !important;">
                		<thead style="background: #183a5d;">
                			<tr style="height: 170px;">
                				<td style="text-align:center; padding-bottom:10px;"><img src="'.home_url().'/wp-content/uploads/logo.png" style="max-width: 250px;"></td>
                			</tr>
                		</thead>
                		<tbody>
                        			<tr>
                        				<td  style="padding: 20px;">
                                            <p>Hello,</p>
                                            <p>'.$full_name.' has requested to delete account. Below to see details</p>
                                            <p>Reason for delete : '.$message.'</p>
                                            <p><a href="'.home_url().'/wp-admin/user-edit.php?user_id='.$user_id.'">click me</a> to see user details.</p>
                                            <p>Thank you</p>
                        				</td>
                        			</tr>
                        		</tbody>
                        		<tfoot style="background: #183a5d;">
                			<tr>
                				<td style="color: #fff; text-align: center; padding: 10px;">©Legacy Care Giving '.date("Y").' Site. All Rights Reserved.</td>
                			</tr>
                		</tfoot>
                        	</table>
                        </div>';

        
        $res  = wp_mail($to, $subject,$body_content,$headers);
        return $res;
    }
    
    //Function for send contact us
    public function contact_us($request){
        global $wpdb;
        $param = $request->get_params(); 
        // $this->isValidToken();
        $user_email = $param['email'];
        $phone = $param['phone_no'];
        $user_message = $param['message'];
        $adminEmail = get_bloginfo('admin_email');
        if(!empty($adminEmail)){
            $message = __('Hello ,') . "<br><br>";
            $message .=__('<span>User Email</span> : <span><b> '.$user_email.'<b></span>')."<br><br>";
            $message .=__('<span>User Phone</span> : <span><b> '.$phone.'<b></span>')."<br>";
            $message .=__('<h4>Message</h4><p> '.$user_message.'<p>')."<br><br>";
            $message .= __('Sincerely') . "<br>";
            $message .= __('Support Team') . "<br>";
            $headers = array('Content-Type: text/html; charset=UTF-8');
            $subject = "Contact Form for Legacy Care Giving.";
            $sent = wp_mail($adminEmail , $subject, $message, $headers);
            
            return $this->successResponse('We have received your message successfully.');    
            
        }else{
          return $this->errorResponse('Please try again.');  
        }
    }
    
}

$serverApi = new CRC_REST_API();
$serverApi->init();
add_filter('jwt_auth_token_before_dispatch',array($serverApi,'jwt_auth'),10,2);


    
