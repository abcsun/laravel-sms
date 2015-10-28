<?php
namespace Toplan\Sms;

use \SmsManager;
use \Validator;
use \Input;
use Illuminate\Routing\Controller;

class SmsController extends Controller
{
    public $smsModel;
    
    public function __construct()
    {
        $this->smsModel = config('laravel-sms.smsModel', 'Toplan/Sms/Sms');
        $this->smsManger = new SmsManager();
    }

    public function postVoiceVerify($rule, $mobile = '')
    {
        $vars = [];
        $vars['success'] = false;
        $input = [
            'mobile' => $mobile,
            'seconds' => Input::get('seconds', 60)
        ];
        $verifyResult = $this->verifyData($input, $rule);
        if (!$verifyResult['pass']) {
            $vars['type'] = $verifyResult['type'];
            $vars['msg'] = $verifyResult['msg'];
            return response()->json($vars);
        }

        $code = SmsManager::generateCode();
        $model = $this->smsModel;
        $result = $model::voice($code)->to($mobile)->send();
        if ($result) {
            $data = SmsManager::getSmsData();
            $data['sent'] = true;
            $data['mobile'] = $mobile;
            $data['code'] = $code;
            //默认15分钟内有效
            $data['deadline_time'] = time() + (15 * 60);
            SmsManager::storeSmsDataToSession($data);
            $vars['success'] = true;
            $vars['msg'] = '语言验证码请求成功，请注意接听';
            $vars['type'] = 'sent_success';
            SmsManager::setCanSendTime($input['seconds']);
        } else {
            $vars['msg'] = '语言验证码请求失败，请重新获取';
            $vars['type'] = 'sent_failed';
        }
        return response()->json($vars);
    }

    //发送短信验证码
    public function postSendCode($rule, $mobile = '')
    {
        $vars = [];
        $vars['success'] = false;
        $input = [
            'mobile' => $mobile,
            'seconds' => 60,//Input::get('seconds', 60)
        ];
        $verifyResult = $this->checkSendConditionToPhone($mobile);

        if (!$verifyResult['passed']) {
            $vars['message'] = $verifyResult['message'];
            $vars['code'] = $verifyResult['code'];
            $vars['result'] = $verifyResult['result'];
            return response()->json($vars);
        }

        $code     = SmsManager::generateCode();
        $minutes  = SmsManager::getCodeValidTime();
        $tempIds  = SmsManager::getVerifySmsTemplateIdArray();
        $template = SmsManager::getVerifySmsContent();
        $content  = vsprintf($template, [$code, $minutes]);
        $sms      = new $this->smsModel;
        $result   = $sms->template($tempIds)
                         ->to($mobile)
                         ->data(['code' => $code,'minutes' => $minutes])
                         ->code($code)
                         ->content($content)
                         ->send();
        if ($result) {
            $data = SmsManager::getSmsData();
            $data['sent'] = true;
            $data['mobile'] = $mobile;
            $data['code'] = $code;
            $data['deadline_time'] = time() + ($minutes * 60);
            // SmsManager::storeSmsDataToSession($data);
            SmsManager::storeSmsCodeToCache($mobile, $code, $minutes);
            $vars['code'] = 1;
            $vars['message'] = '发送成功';
            $vars['result'] = '发送成功';
            // SmsManager::setCanSendTime($input['seconds']);
        } else {
            $vars['code'] = 0;
            $vars['message'] = '发送失败，请重新获取';
            $vars['result'] = '发送失败，请重新获取';
        }
        return response()->json($vars);
    }

    public function checkSendConditionToPhone($phone)
    {
        $vars = [];
        $vars['passed'] = true;
        // var_dump(SmsManager::canSendAgainToPhone($phone));
        if (!SmsManager::canSendAgainToPhone($phone)) {
            $vars['passed'] = false;
            $vars['message'] = "请在60秒后重试";
            $vars['result'] = "请在60秒后重试";
            $vars['code'] = '0';
        }
        $validator = Validator::make(array('phone'=>$phone), [
            'phone' => 'required|mobile'
        ]);
        if ($validator->fails()) {
            $vars['passed'] = false;
            $vars['message'] = '手机号格式错误';
            $vars['result'] = '手机号格式错误';
            $vars['code'] = '-1';
        }

        return $vars;
    }

    private function verifyData(Array $input, $rule)
    {
        $vars = [];
        $vars['passed'] = true;
        if (!SmsManager::canSendAgainToPhone($input['mobile'])) {
            $vars['passed'] = false;
            $vars['msg'] = "请求无效，请在{$input['seconds']}秒后重试";
            $vars['type'] = 'waiting';
        }
        if (SmsManager::hasRule('mobile', $rule)) {
            SmsManager::rule('mobile', $rule);
        }
        $validator = Validator::make($input, [
            'mobile' => 'required|mobile'
        ]);
        if ($validator->fails()) {
            $vars['passed'] = false;
            $vars['msg'] = '手机号格式错误，请输入正确的11位手机号';
            $vars['type'] = 'mobile_error';
        }
        if (SmsManager::isCheck('mobile')) {
            $validator = Validator::make($input, [
                'mobile' => SmsManager::getRule('mobile')
            ]);
            if ($validator->fails()) {
                $vars['passed'] = false;
                if ($rule == 'check_mobile_unique') {
                    $vars['msg'] = '该手机号码已存在';
                } elseif ($rule == 'check_mobile_exists') {
                    $vars['msg'] = '不存在此手机号码';
                } else {
                    $vars['msg'] = '抱歉，你的手机号未通过合法性检测';
                }
                $vars['type'] = $rule;
            }
        }
        return $vars;
    }

    public function getInfo()
    {
        $html = '<meta charset="UTF-8"/><h2 align="center" style="margin-top: 20px;">Hello, welcome to laravel-sms for l5.</h2>';
        $html .= '<p style="color: #666;"><a href="https://github.com/toplan/laravel-sms" target="_blank">laravel-sms源码</a>托管在GitHub，欢迎你的使用。如有问题和建议，欢迎提供issue。当然你也能为该项目提供开源代码，让laravel-sms支持更多服务商。</p>';
        $html .= '<hr>';
        $html .= '<p>你可以在调试模式(设置config/app.php中的debug为true)下查看到存储在session中的验证码短信相关数据(方便你进行调试)：</p>';
        echo $html;
        if (config('app.debug')) {
            dd(SmsManager::getSmsDataFromSession());
        } else {
            echo '<p align="center" style="color: #ff0000;;">现在是非调试模式，无法查看验证码短信数据</p>';
        }
    }
}
