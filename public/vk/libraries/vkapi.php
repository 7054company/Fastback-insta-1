<?php
if(!class_exists("vkapi")){
    class vkapi{
        private $ClientID;
        private $ClientSecret;
        private $redirect_uri;
        private $access_token;
        private $params;
        private $pin;
        private $version = "5.73";

        public function __construct($client_id = null, $client_secret = null){
            $this->ClientID = $client_id;
            $this->ClientSecret = $client_secret;
            $this->redirect_uri = $this->redirect_uri = "http://oauth.vk.com/authorize?client_id=".$client_id. "&scope=wall,photos,video,friends,audio,docs,groups,users,email,pages,offline&redirect_uri=http://oauth.vk.com/blank.html&display=page&v=5.73&response_type=token";
            $this->params = array(
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $this->redirect_uri,
                'response_type' => 'code'
            );
        }

        function login_url(){
            return 'http://oauth.vk.com/authorize?' . urldecode(http_build_query($this->params));;
        }

        function get_access_token(){
            try {
                if(post("code")){
                    $params = [
                        'client_id' => $this->ClientID,
                        'client_secret' => $this->ClientSecret,
                        'code' => post("code"),
                        'redirect_uri' => $this->redirect_uri
                    ];

                    $curl = curl_init();
                    curl_setopt_array(
                        $curl, 
                        array(
                            CURLOPT_RETURNTRANSFER => 1, 
                            CURLOPT_SSL_VERIFYHOST => false,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_URL => 'https://oauth.vk.com/access_token' . '?' . urldecode(http_build_query($params)),
                            CURLOPT_HEADER => false
                        )
                    );
                    $resp = curl_exec($curl);
                    curl_close($curl);

                    $result = (object)json_decode($resp);
                    if(isset($result->access_token)){
                        $this->access_token = $result->access_token;
                        return $result->access_token;
                    }else{
                        ms(array(
                            "status"  => "error",
                            "message" => $result->error_description
                        ));
                    }
                }else{
                    ms(array(
                        "status"  => "error",
                        "message" => lang("please_enter_vk_code")
                    ));
                }
                
            } catch (Exception $e) {
            }
        }

        function set_access_token($access_token){
            $this->access_token = $access_token;
        }

        function get_user_info(){
            $params = array(
                'fields' => 'uid,screen_name,photo_big,wall,offline'
            ); 

            $result = $this->curl_get("users.get", $params);
            return $result;
        }

        function get_groups(){
            $result = $this->curl_get('groups.get', array('access_token' => $this->access_token, 'extended' => 1, 'fields' => 'last_name,first_name,screen_name,wall_comments,can_post,can_write_private_message,contacts', 'filter' => 'admin,editor'));
            return $result;
        }

        function post($data){
            $data     = (object)$data;
            $response = array();
            try {
                $data->data = (object)json_decode($data->data);
                $media      = $data->data->media;
                $caption    = $data->data->caption;
                $link       = $data->data->link;
                //$title      = $data->data->title;
                $params     = array('status' => $caption);

                switch ($data->type) {
                    case 'text':
                        $params = array(
                            'owner_id' => $data->group_id, 
                            'message' => urlencode($caption)
                        );
                        break;

                    case 'media':

                        if(check_image($media[0])){

                            $attachments = $this->upload_photo(0, $media, false);

                            $params = array(
                                'owner_id' => $data->group_id, 
                                'message' => urlencode($caption), 
                                'attachments' => $attachments
                            );

                        }else{

                            $attachments = $this->upload_video(array(
                                'name' => '',
                                'description' => urlencode($caption),
                                'wallpost' => 1
                            ), $media[0]);

                            $params = array(
                                'owner_id' => $data->group_id, 
                                'message' => urlencode($caption), 
                                'attachments' => $attachments
                            );
                            
                        }
                        break;

                    case 'link':

                        $params = array(
                            'owner_id' => $data->group_id, 
                            'message' => urlencode($caption),
                            'attachments' => urlencode($link)
                        );

                        break;
                }

                $response = $this->curl_post("wall.post", $params);

                if(!isset($response->error)){
                    return "wall".$data->group_id."_".$response->post_id;
                }else{
                    throw new Exception($response->error->error_msg);
                }
            } catch (Exception $e) {
                return array(
                    "status"  => "error",
                    "message" => $e->getMessage()
                );
            }
        }

        //Other features
        public function upload_photo($gid = 0, $files = array(), $return_ids = false, $additional_data = array(), $usleep = 0){
            
            if(count($files) == 0) return false;
            if(!function_exists('curl_init')) return false;
            $data_json = $this->curl_post('photos.getWallUploadServer', array( 'group_id'=> intval($gid) ));
            if(!isset($data_json->upload_url)) return false;
            $temp = array_chunk($files, 4);
            $attachments = array();
            
            foreach($temp as $chunk_index => $temp_chunk){
                
                if($chunk_index) usleep($usleep);
                
                $files = [];
                
                foreach ($temp_chunk as $key => $data) {
                    $data = get_path_file($data);
                    $path = realpath($data);

                    $info = @getimagesize($path);
                    if ($info === false) {
                        throw new \RuntimeException(sprintf('File "%s" is not an image.', $data));
                    }

                    if($path){
                        $files['file' . ($key+1)] = (class_exists('CURLFile', false)) ? new CURLFile(realpath($data)) : '@' . realpath($data);
                    }
                }

                $upload_url = $data_json->upload_url;
                $ch = curl_init($upload_url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-type: multipart/form-data"));
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $files);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $upload_data = json_decode(curl_exec($ch), true);
                $upload_data['group_id'] = intval($gid);
                $upload_data += $additional_data;
                
                usleep($usleep);
                
                $response = $this->curl_post('photos.saveWallPhoto', $upload_data);
                if(!isset($response->error)){
                    if(isset($response) && count($response) > 0){
                        foreach($response as $key => $photo){
                            if($return_ids)
                                $attachments[] = $photo->id;
                            else
                                $attachments[] = 'photo'.$photo->owner_id.'_'.$photo->id;
                        }
                    }
                }else{
                    throw new Exception($response->error->error_msg);
                }
            }  

            return $attachments; 
        }

        public function upload_video($options = [], $file = false){
            if(!is_array($options)) return false;
            if(!function_exists('curl_init')) return false;
            $data_json = $this->curl_post('video.save', $options);

            if(!isset($data_json->upload_url)) return false;
            $attachment = 'video'.$data_json->owner_id.'_'.$data_json->video_id;
            $upload_url = $data_json->upload_url;
            $ch = curl_init($upload_url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-type: multipart/form-data"));
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible;)");
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            // если указан файл то заливаем его отправкой POST переменной video_file
            $file = get_path_file($file);
            if($file && file_exists($file)){
                //@todo надо протестировать заливку
                $path = realpath($file);
                if(!$path) return false;
                $files['video_file'] = (class_exists('CURLFile', false)) ? new CURLFile($file) : '@' . $file;
                curl_setopt($ch, CURLOPT_POSTFIELDS, $files);
                curl_exec($ch);
            // иначе просто обращаемся по адресу (ну надо так!)
            } else {
                curl_exec($ch);
            }
            return $attachment;
        }

        function curl_get($method, $params){
            if($this->access_token != ""){
                $params['access_token'] = $this->access_token;
            }

            $params['v'] = $this->version;

            $curl = curl_init();
            curl_setopt_array(
                $curl, 
                array(
                    CURLOPT_RETURNTRANSFER => 1, 
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_URL => 'https://api.vk.com/method/'. $method . '?' . urldecode(http_build_query($params)),
                    CURLOPT_HEADER => false
                )
            );
            $resp = curl_exec($curl);
            curl_close($curl);
            $result = (object)json_decode($resp);

            if(isset($result->response)){
                return $result->response;
            }

            return $result;
        }

        function curl_post($method, $params){
            if($this->access_token != ""){
                $params['access_token'] = $this->access_token;
            }

            $params['v'] = $this->version;

            $curl = curl_init();
            curl_setopt_array(
                $curl, 
                array(
                    CURLOPT_RETURNTRANSFER => 1, 
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_URL => 'https://api.vk.com/method/'. $method . '?' . urldecode(http_build_query($params)),
                    CURLOPT_HEADER => false
                )
            );
            $resp = curl_exec($curl);
            curl_close($curl);
            $result = (object)json_decode($resp);

            if(isset($result->response)){
                return $result->response;
            }

            return $result;
        }
    }
}
?>