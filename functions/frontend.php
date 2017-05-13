<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

function sprot_style() {
    osc_enqueue_style('sp-styles', osc_plugin_url('spamprotection/assets/css/style.css').'style.css?'.time());
            
    osc_register_script('spam_protection-frontend', osc_plugin_url('spamprotection/assets/js/script.js') . 'script.js?'.time(), array('jquery'));
    osc_enqueue_script('spam_protection-frontend');            
    osc_register_script('spam_protection-hideMail', osc_plugin_url('spamprotection/assets/js/jquery.hideMyEmail.min.js') . 'jquery.hideMyEmail.min.js', array('jquery'));
    osc_enqueue_script('spam_protection-hideMail');
}

function sp_check_item($item) {
    $user = osc_logged_user_id();
    $check = spam_prot::newInstance()->_checkForSpam($item);
    if (is_array($check)) {
        spam_prot::newInstance()->_markAsSpam($check['params'], $check['reason']);
    }
}

function sp_check_comment($id) {
    $user = osc_logged_user_id();
    $check = spam_prot::newInstance()->_checkComment($id);
    if (is_array($check)) {
        spam_prot::newInstance()->_markCommentAsSpam($check['params'], $check['reason']);
    }
}

function sp_delete_comment($id) {
    $table = spam_prot::newInstance()->_table_sp_comments;
    spam_prot::newInstance()->dao->delete($table, 'fk_i_comment_id = '.$id);
}

function sp_add_honeypot() {
    $hp = spam_prot::newInstance()->_get('honeypot_name');
    if (empty($hp)) {
        $hp = 'sp_price_range';
    }
    echo '<input type="text" name="'.$hp.'" class="sp_form_field" value="" />';    
}

function sp_contact_form() {
    if (spam_prot::newInstance()->_get('sp_contact_honeypot') == '1') {
        $hpn = spam_prot::newInstance()->_get('contact_honeypot_name');
        $hpv = spam_prot::newInstance()->_get('contact_honeypot_value');
        
        if (empty($hpn)) {
            $hp = 'yourDate';
        } if (empty($hpv)) {
            $hp = 'asap';
        }
        echo '
            <input type="text" id="'.$hpn.'" name="'.$hpn.'" class="sp_form_field" value="" />
            <label class="sp_form_field">
                Please Solve: 3+4
                <input type="text" id="bot_check" name="captcha" value="" />
            </label>
            <script>
                $(document).ready(function(){
                    $("#'.$hpn.'").val("'.$hpv.'");
                });
            </script>
            ';
    }
    if (osc_logged_user_id()) {
        echo '<input type="text" name="user_id" class="sp_form_field" value="'.osc_logged_user_id().'" />';
    }    
}

function sp_check_contact_item($data) {
                    
    $item  = Item::newInstance()->findByPrimaryKey($data['item']['fk_i_item_id']);
    View::newInstance()->_exportVariableToView('item', $item);
    
    $params = Params::getParamsAsArray();    
    $check = spam_prot::newInstance()->_checkContact($params);
        
    if (is_array($check)) {        
        $uniqid = uniqid();
        spam_prot::newInstance()->_markContactAsSpam($check['params'], $check['reason'], $uniqid);
        
        $contactID = spam_prot::newInstance()->_searchSpamContact($uniqid);
        osc_add_flash_error_message(sprintf(__("Your email must be verified by a moderator because it has been identified as spam. After successful verification we will forward your e-mail to the user. Click Delete if you do not want your message to be moderated. <a href='%s'>Delete</a>", "spamprotection"), '/?delete_contact_mail='.$contactID.'&token='.$uniqid));
        
        header('Location: '.osc_item_url());
        exit;
    }       
}

function sp_check_contact_user($id, $yourEmail, $yourName, $phoneNumber, $message) {
    $data = array(
        'id'          => $id,
        'yourEmail'   => $yourEmail,
        'yourName'    => $yourName,
        'message'     => $message,
        'phoneNumber' => $phoneNumber
    );
    sp_check_contact_item($data);    
}

function sp_check_user_login() {
    $token = Params::getParam('token');
    $action = Params::getParam('action');
    $email = Params::getParam('email');
    $password = Params::getParam('password', false, false);
    
    if ($action == 'login_post' && !empty($email) && !empty($password)) {
        
        spam_prot::newInstance()->_increaseUserLogin($email);
        $ip = spam_prot::newInstance()->_IpUserLogin();
                
        $logins = spam_prot::newInstance()->_countLogin($email, 'user', $ip);
        $max_logins = spam_prot::newInstance()->_get('sp_security_login_count');
        
        if (!empty($data_token)) {
            ob_get_clean();
            osc_add_flash_error_message(__('<strong>Information!</strong> Your account is disabled due to too much of false login attempts. Please contact support.', 'spamprotection'));    
            header('Location: '.osc_base_url());
            exit;
        } elseif ($logins <= $max_logins && spam_prot::newInstance()->_checkUserLogin($email, $password)) {            
            spam_prot::newInstance()->_resetUserLogin($email);    
        } elseif (!spam_prot::newInstance()->_checkUserLogin($email, $password)) {
            if ($logins >= $max_logins) {
                spam_prot::newInstance()->_handleUserLogin($email, $ip);
                if ($logins == $max_logins) {
                    spam_prot::newInstance()->_informUser($email, 'user');    
                } if (spam_prot::newInstance()->_get('sp_security_login_inform') == '1') {                
                    ob_get_clean();
                    osc_add_flash_error_message(__('<strong>Information!</strong> Your account is disabled due to too much of false login attempts. Please contact support.', 'spamprotection'));
                }
            } elseif (empty($logins) || $logins < $max_logins) {
                if (spam_prot::newInstance()->_get('sp_security_login_inform') == '1') {
                    ob_get_clean();
                    osc_add_flash_error_message(sprintf(__('<strong>Warning!</strong> Only %d login attempts remaining', 'spamprotection'), ($max_logins-$logins)));    
                }
            }
            header('Location: '.osc_user_login_url());
            exit;   
        }
    }
}

function sp_add_honeypot_security() {
    echo '<input id="token" type="text" name="token" value="" class="form-control sp_form_field" autocomplete="off">';    
}

function sp_check_user_registrations() {     
    $email = Params::getParam('s_email');
    
    $check_mail = spam_prot::newInstance()->_get('sp_check_stopforumspam_mail');
    $check_ip = spam_prot::newInstance()->_get('sp_check_stopforumspam_ip');
    
    if (Params::getParam('action') == 'register_post' && spam_prot::newInstance()->_get('sp_check_registrations') >= '2') {
        if (($email = filter_var($email, FILTER_VALIDATE_EMAIL)) !== false) {        
            $check = spam_prot::newInstance()->_get('sp_check_registrations');
            $mails = explode(",", spam_prot::newInstance()->_get('sp_check_registration_mails'));
            $domain = substr(strrchr($email, "@"), 1);
            $error = false;
            
            if ($check == '2') {
                if (!in_array($domain, $mails)) { $error = sprintf(__("Sorry, but you cannot use this email address: %s", "spamprotection"), $domain); }   
            } elseif ($check == '3') {
                if (in_array($domain, $mails)) { $error = sprintf(__("Sorry, but you cannot use this email address: %s", "spamprotection"), $domain); }            
            }
        } else {
            $error = __("Sorry, but you need to use a valid email address.", "spamprotection"); 
        }
        
        if ($error) {
            ob_get_clean();
            osc_add_flash_error_message($error);        
            header('Location: '.osc_register_account_url());
            exit;
        }
    } 
    
    if ($check_mail == '1' || $check_ip == '1') {
        
        $url = 'http://api.stopforumspam.org/api?serial';
        $frequency = spam_prot::newInstance()->_get('sp_stopforumspam_freq');
        $confidence = spam_prot::newInstance()->_get('sp_stopforumspam_susp');        
        
        if ($check_mail == '1') {
            $email_encoded = urlencode(iconv('GBK', 'UTF-8', $email)); 
            $url .= '&email='.$email_encoded; 
        } if ($check_ip == '1') {            
            $ip = spam_prot::newInstance()->_IpUserLogin(); 
            $url .= '&ip='.$ip; 
        }
        
        $data = unserialize(osc_file_get_contents($url));
        $data_mail = $data['email'];
        $data_ip = $data['ip'];
        
        if (is_array($data_mail)) {
            
            $data_freq = $data_mail['frequency'];
            $data_conf = $data_mail['confidence'];
            
            if ($data_freq > $frequency || $data_conf > $confidence) {                
                ob_get_clean();
                osc_add_flash_error_message(__("Sorry, but your email address is listed because of spam on <a href=\"https://www.stopforumspam.com\">StopForumSpam</a>. Due to this, you cannot register your Account here using this email address, but you can request the deleting of your email address <a href=\"https://www.stopforumspam.com/removal\">Here</a>", "spamprotection"));        
                header('Location: '.osc_register_account_url());
                exit;    
            }
        }
        
        if (is_array($data_ip)) {            
            $data_freq = $data_ip['frequency'];
            $data_conf = $data_ip['confidence'];
            
            if ($data_freq > $frequency || $data_conf > $confidence) {                
                ob_get_clean();
                osc_add_flash_error_message(__("Sorry, but your IP is listed because of spam on <a href=\"https://www.stopforumspam.com\">StopForumSpam</a>. Due to this, you cannot register your Account here, but you can request the deleting of your IP <a href=\"https://www.stopforumspam.com/removal\">Here</a>", "spamprotection"));        
                header('Location: '.osc_register_account_url());
                exit;    
            }
        }
    }  
}

function sp_unban_cron() {
    spam_prot::newInstance()->_unbanUser();    
}
?>