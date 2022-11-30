<?php
 /*
 Plugin Name:CRC
 Description:This is custom plugin for Api purpose.
 Version:1.0.0
 Author:Rahman
 */

 
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class REST_APIS extends WP_REST_Controller{
    private $api_namespace;
    private $api_version;
    public $user_token;
    public $user_id;

    public function __construct(){
        $this->api_namespace = "api/v";
        $this->api_version = "1";
        $this->init();

        $headers = getallheaders();
        if(isset($headers['Authorization'])){
            if(preg_match('/Bearer\s(\S+)/',$headers['Authorization'],$matches)){
                $this->user_token = $matches[1];
            }
        }
    }

    private function successResponse($message='',$data=array(),$total=array()){
        $response = array();
        $response['status'] = "Success";
        $response['error_type'] = "";
        $response['message'] = $message;
        $response['data'] = $data;
        if(!empty($total)){
            $response['pagination'] = $total;
        }
        return new WP_REST_Response($response, 200);
    }

    private function errorResponse($message='',$type='ERROR'){
        $response = array();
        $response['status'] = "failed";
        $response['error_type'] = $type;
        $response['message'] = $message;
        $response['data'] = array();
        return new WP_REST_Response($response, 400);
    }

    public function register_routes(){
        $namespace =$this->api_namespace . $this->api_version;
        $privateItems = array('get_profile_by_id');
        $getItems = array();
        $publicItems = array('register_user');

        foreach($privateItems as $Items){
            register_rest_route($namespace,"/".$Items,array(
                array(
                    "methods" => "POST",
                    "callback" => array($this,$Items),
                    "permission_callback" => !empty($this->user_token)?'__return_true':'__return_false'
                ),
            ));
        }

        foreach($getItems as $Items){
            register_rest_route($namespace,"/".$Items,array(
                array(
                    "methods" => "GET",
                    "callback" => array($this, $Items)
                ),
            ));
        }

        foreach($publicItems as $Items){
            register_rest_route($namespace,"/".$Items,array(
                array(
                    "methods" => "POST",
                    "callback" => array($this, $Items)
                ),
            ));
        }
    }

    public function init(){
        add_action('rest_api_init',array($this,'register_routes'));
        add_action('rest_api_init',array($this,'register_routes1'));
        add_action('rest_api_init',function(){
            remove_filter('rest_pre_serve_request','rest_send_cors_headers');
            add_filter('rest_pre_serve_request',function($value){
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Methods: POST,GET,PUT,OPTIONS,DELETE');
                header('Access-Control-Allow-Credentials: true');
                return $value;
            });
        },15);
    }

    public function isUserExists($user){
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->users WHERE ID = %d",$user));
        if($count == 1){
            return true;
        }else{
            return false;
        }
    }

    public function getUserIdByToken($token){
        $decoded_array = array();
        if($token){
            try{
                // $decoded = JWT::decode($token,JWT_AUTH_SECRET_KEY,array('HS256'));
                $decoded = JWT::decode($token, new Key(JWT_AUTH_SECRET_KEY, 'HS256'));
                $decoded_array = (array) $decoded;
            }
            catch(\Firebase\JWT\ExpiredException $e){
                return false;
            }
        }
        if(count($decoded_array) > 0){
            $user_id = $decoded_array['data']->user->id;
        }
        if($this->isUserExists($user_id)){
            return $user_id;
        }else{
            return false;
        }
    }

    function jwt_auth($data,$user){
        unset($data['user_nicename']);
        unset($data['user_display_name']);
        $site_url = site_url();
        $result = $this->get_profile($user->ID);
        $result['token'] = $data['token'];
        return $this->successResponse('User Logged in successfully',$result);
    }

    private function isValidToken(){
        $this->user_id = $this->getUserIdByToken($this->user_token);
    }

    public function get_profile($user_id){
        global $wpdb;
        $userInfo = get_user_by('ID',$user_id);
        $first_name = get_user_meta($user_id,"first_name",true);
        $last_name = get_user_meta($user_id,"last_name",true);
        $f_name = !empty($first_name)?$first_name:"";
        $l_name = !empty($last_name)?$last_name:"";
        $full_name = $f_name." ".$l_name;

        $result = array(
            "user_id" => $userInfo->ID,
            "user_email" => $userInfo->user_email,
            "full_name" => $full_name,
        );

        if(!empty($userInfo)){
            return $result;
        }else{
            return 0;
        }

    }

    public function get_profile_by_id($request){
        global $wpdb;
        $param = $request->get_params();
        $this->isValidToken();
        $user_id = isset($this->user_id)?$this->user_id:$param['user_id'];
        if(empty($user_id)){
            return $this->errorResponse('Please Enter Valid Token.');
        }else{
            $userInfo = get_userdata($user_id);
            $user_email = $userInfo->user_email;
            $f_name = get_user_meta($user_id,"first_name",true);
            $l_name = get_user_meta($user_id,"last_name",true);
            $first_name = isset($f_name)?$f_name:"";
            $last_name = isset($l_name)?$l_name:"";
            $full_name = $first_name." ".$last_name;
            $user_array[]=array(
                "id" => $user_id,
                "email" => $user_email,
                "name" => $full_name
            );
            return $this->successResponse('User Get Successfully.',$user_array);
        }
    }


    public function register_user($request){
        global $wpdb;
        $param = $request->get_params();
        $first_name = $param['first_name'];
        $last_name = $param['last_name'];
        $email = $param['email'];
        $phone = $param['phone_no'];
        $password = $param['password'];

        $user_id = wp_create_user($email,$password,$email);
        update_user_meta($user_id,"first_name",$first_name);
        update_user_meta($user_id,"phone_no",$phone);
        return $this->successResponse('User registered successfully.');
    }

    public function register_routes1(){
        $namespace =$this->api_namespace . $this->api_version;
        $privateItems = array('get_profile_by_id');
        $getItems = array();
        $publicItems = array('register','create_post');

        foreach($privateItems as $Items){
            register_rest_route($namespace,"/".$Items,array(
                array(
                    "methods" => "POST",
                    "callback" => array($this,$Items),
                    "permission_callback" => !empty($this->user_token)?'__return_true':'__return_false'
                ),
            ));
        }

        foreach($getItems as $Items){
            register_rest_route($namespace,"/".$Items,array(
                array(
                    "methods" => "GET",
                    "callback" => array($this, $Items)
                ),
            ));
        }

        foreach($publicItems as $Items){
            register_rest_route($namespace,"/".$Items,array(
                array(
                    "methods" => "POST",
                    "callback" => array($this, $Items)
                ),
            ));
        }
    }

    public function register($request){
        global $wpdb;
        $param = $request->get_params();
        $full_name = $param['full_name'];
        $email = $param['email'];
        $phone = $param['phone_no'];
        $password = $param['password'];

        $user_id = wp_create_user($email,$password,$email);
        update_user_meta($user_id,"full_name",$full_name);
        update_user_meta($user_id,"phone_no",$phone);
        return $this->successResponse('Registered successfully.');
    }

    public function create_post($request){
        global $wpdb;
       $param = $request->get_params();
       $this->isValidToken();
       $user_id = !empty($this->user_id)?$this->user_id:$param['user_id'];
       $post_title = $param['post_title'];
       $content = $param['post_content'];
            
       if(empty($user_id)){
           return $this->errorResponse('Please enter valid token.');
        }
        else{
            $args = array(
                "post_title" => $post_title,
                "post_status" => "publish",
                "post_content" => $content,
                "post_type" => "post"
            );
            wp_insert_post($args);
            return $this->successResponse('Post Created successfully.');
        }
    }
}
$serverApi = new REST_APIS();
$serverApi->init();
add_filter('jwt_auth_token_before_dispatch',array($serverApi,'jwt_auth'),10,2);
 


?>