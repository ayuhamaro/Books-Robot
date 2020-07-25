<?php
    require 'vendor/autoload.php';
    use Mailgun\Mailgun;

    class books{
        const CLASS_URL = 'https://www.books.com.tw/web/books_nbtopm_%s/?loc=P_003_0%s';
        const TAAZE_QUERY = 'https://www.taaze.tw/rwd_searchResult.html?keyType[]=0&keyword[]=%s';
        const XPATH_ROOT = "//div[contains(@class, 'wrap')]/div[contains(@class, 'item')]";
        const XPATH_TITLE = "/div[contains(@class, 'msg')]/h4/a";
        const XPATH_AUTHOR = "[%s]/div[contains(@class, 'msg')]/ul[contains(@class, 'list')]/li[contains(@class, 'info')]/a";
        const XPATH_LINK = "[%s]/div[contains(@class, 'msg')]/h4/a/@href";
        const XPATH_IMG = "[%s]/a/img/@src";
        const ADMIN_MAIL = 'xxx';
        const MAIL_SENDER = 'xxx';
        const MAIL_SUB_PREFIX = '博客來新書';
        const MAILGUN_KEY = 'xxx';
        const MAILGUN_DOMAIN = 'xxx';
        const USER_AGENT = 'xxx';

        private $data_file = "data.json";
        private $cookie_file = "cookie.txt";
        private $msg_file = "msg.txt";
        private $sub_mail_list_file = "mail_list.txt";
        private $class_type = array(0 => '文學小說',
                                    1 => '商業理財',
                                    2 => '藝術設計',
                                    3 => '人文史地',
                                    4 => '社會科學',
                                    5 => '自然科普',
                                    6 => '心理勵志',
                                    7 => '醫療保健',
                                    8 => '飲食',
                                    9 => '生活風格',
                                    10 => '旅遊',
                                    11 => '宗教命理',
                                    12 => '親子教養',
                                    13 => '童書/青少年文學',
                                    14 => '輕小說',
                                    15 => '漫畫',
                                    16 => '語言學習',
                                    17 => '考試用書',
                                    18 => '電腦資訊',
                                    19 => '專業/教科書/政府出版品',);
        private $subscriber = array();
        private $resource;

        function __construct(){
            $this->data_file = dirname(__FILE__).'/'.$this->data_file;
            $this->cookie_file = dirname(__FILE__).'/'.$this->cookie_file;
            $this->msg_file = dirname(__FILE__).'/'.$this->msg_file;
            $this->sub_mail_list_file = dirname(__FILE__).'/'.$this->sub_mail_list_file;

            $sub_mail_list_file = file_get_contents($this->sub_mail_list_file);
            $sub_mail_list_array = explode("\n", $sub_mail_list_file);
            foreach($sub_mail_list_array as $key => $mail){
                $this->subscriber[$key] = trim($mail);
            }
        }

        private function __curl($url, $timeout = 300, $post_data = null){
            $retry = 0; //重試旗標
            $this->resource = curl_init();
            curl_setopt($this->resource, CURLOPT_URL, $url);
            if($post_data != null){
                curl_setopt($this->resource, CURLOPT_POST, 1);
                curl_setopt($this->resource, CURLOPT_POSTFIELDS, $post_data);
            }
            curl_setopt($this->resource, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($this->resource, CURLOPT_CONNECTTIMEOUT, 300);
            curl_setopt($this->resource, CURLOPT_COOKIEFILE, $this->cookie_file);
            curl_setopt($this->resource, CURLOPT_COOKIEJAR, $this->cookie_file);
            curl_setopt($this->resource, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->resource, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->resource, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($this->resource, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($this->resource, CURLOPT_FTP_USE_EPSV, 0);
            curl_setopt($this->resource, CURLOPT_VERBOSE, 0);   //顯示cUrl運作細節
            curl_setopt($this->resource, CURLOPT_MAXREDIRS, 4);
            curl_setopt($this->resource, CURLOPT_USERAGENT, self::USER_AGENT);
            
            do{
                if($retry > 0){
                    sleep(5);
                    $msg = sprintf(">>> Retry curl %s time(s) at: %s\n", $retry, $url);
                    echo $msg;
                }
                $content = curl_exec($this->resource);
                $info = curl_getinfo($this->resource);
                $error = curl_error($this->resource);
                $retry ++;
            }while($retry < 10 && $info["http_code"] !== 200); //最多重試10次
            return array('content' => $content,
                            'info' => $info,
                            'error' => $error);
        }

        public function __send_mail($new_book = array(), $error = ""){
            $subject = sprintf("%s(%s)", self::MAIL_SUB_PREFIX, date("Y-m-d H:i:s")); //信件標題
            $headers = sprintf("From: %s\r\n", self::MAIL_SENDER);
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=utf-8\r\n";
            $msg = "";
            
            if($error === ''){
                foreach ($new_book as $class_name => $books) {
                    $msg .= "<h2>".$class_name."</h2>\r\n";
                    $msg .= "<ul>\r\n";
                    foreach ($books as $title => $value) {
                        $msg .= "<li>\r\n";
                        $msg .= sprintf('<a href="%s" target="_blank">', $value['link'])."\r\n";
                        $msg .= sprintf('<img src="%s"><br />', $value['img'])."\r\n";
                        $msg .= sprintf('%s (%s)', $title, $value['author'])."\r\n</a>\r\n";
                        $msg .= sprintf('<a href="%s" target="_blank">[讀冊搜尋]</a>', sprintf(self::TAAZE_QUERY, $title))."\r\n";
                        $msg .= "</li>\r\n";
                    }
                    $msg .= "</ul>\r\n";
                }
                file_put_contents($this->msg_file, $msg);

                $mg = Mailgun::create(self::MAILGUN_KEY);
                foreach($this->subscriber as $mail)
                {
                    $mg->messages()->send(self::MAILGUN_DOMAIN, [
                        'from'    => sprintf('%s <%s>', self::MAIL_SUB_PREFIX, self::MAIL_SENDER),
                        'to'      => $mail,
                        'subject' => $subject,
                        'html'    => $msg
                    ]);
                }
            }else{
                mail(self::ADMIN_MAIL, $subject, $error, $headers);
            }
        }
        
        private function __get_link($url){
            $result = $this->__curl($url);
            $html = $result['content'];
            libxml_use_internal_errors(true);
            
            $dom = new DomDocument;
            $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
            $dom->encoding = 'utf-8'; //設定原始碼為UTF-8
            $xpath = new DomXPath($dom);
            
            $array_title = array();
            $nodes = $xpath->query(self::XPATH_ROOT.self::XPATH_TITLE);
            foreach ($nodes as $title) {
                $array_title[] = trim($title->textContent);
            }
            
            $list = array();
            foreach ($array_title as $key => $title) {
                $value = array(
                    'author' => '',
                    'link' => '',
                    'img' => '',
                );

                $nodes = $xpath->query(self::XPATH_ROOT.sprintf(self::XPATH_AUTHOR, $key + 1));
                if($nodes->length == 1){
                    $value['author'] = $nodes->item(0)->textContent;
                }
                $nodes = $xpath->query(self::XPATH_ROOT.sprintf(self::XPATH_LINK, $key + 1));
                if($nodes->length == 1){
                    $value['link'] = $nodes->item(0)->textContent;
                }
                $nodes = $xpath->query(self::XPATH_ROOT.sprintf(self::XPATH_IMG, $key + 1));
                if($nodes->length == 1){
                    $value['img'] = str_replace('&w=170&h=170', '&w=120&h=120', $nodes->item(0)->textContent);
                }
                $list[$title] = $value;
            }
            
            return $list;
        }
        
        public function get(){
            if( ! file_exists($this->data_file)){
                $data = array_fill_keys(array_keys($this->class_type), array());
                file_put_contents($this->data_file, json_encode($data));
            }else{
                $json = file_get_contents($this->data_file);
                $data = json_decode($json, TRUE);
            }

            $new_book = array();
            foreach ($this->class_type as $key => $value) {
                $list = $this->__get_link(sprintf(self::CLASS_URL, str_pad($key + 1, 2, '0', STR_PAD_LEFT), str_pad($key + 1, 2, '0', STR_PAD_LEFT)));
                if(count($list) == 0){
                    $this->__send_mail(array(), $value."書籍擷取失敗");
                    echo $value."書籍擷取失敗\n";
                    return false;
                }
                
                if( ! array_key_exists($key, $data)){
                    $data[$key] = array();
                }
                $diff_array = array_diff_key($list, $data[$key]);
                if(count($diff_array) > 0){
                    $new_book[$value] = $diff_array;
                    $data[$key] = $list;
                }else{
            
                }
                sleep(3);
            }

            if(count($new_book) > 0){
                $this->__send_mail($new_book);
            }else{
                echo "無須發送\n";
            }
            file_put_contents($this->data_file, json_encode($data));
        }
    
    }

    $books = new books;
    $books->get();
