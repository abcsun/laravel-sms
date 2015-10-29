<?php
namespace Toplan\Sms;

use \Session;
use \Cache;

class SmsManager
{
    /**
     * the application instance
     * @var
     */
    protected $app;

    /**
     * agent instances
     * @var
     */
    protected $agents;

    /**
     * sms data
     * @var
     */
    protected $smsData;

    /**
     * construct
     * @param $app
     */
	public function __construct($app)
    {
        $this->app = $app;
        $this->init();
    }

    /**
     * sms manager init
     */
    private function init()
    {
        $data = [
                'sent' => false,
                'mobile' => '',
                'code' => '',
                'deadline_time' => 0,
                'verify' => config('laravel-sms.verify'),
            ];
        $this->smsData = $data;
    }

    /**
     * get data
     * 获取发送相关信息
     * @return mixed
     */
    public function getSmsData()
    {
        return $this->smsData;
    }

    /**
     * set sent data
     * 设置发送相关信息
     * @param array $data
     */
    public function setSmsData(Array $data)
    {
        $this->smsData = $data;
    }

    /**
     * put sms data to session
     * @param array $data
     */
    public function storeSmsDataToSession(Array $data = [])
    {
        $data = $data ?: $this->smsData;
        $this->smsData = $data;
        Session::put($this->getSessionKey(), $data);
    }

    /**
     * 针对验证类短信，将手机号和生成的验证码组合后进行缓存，用于后期验证
     * key: phone-code
     * VC - verfiy code
     * HS - has sent
     * @param array $data
     */
    public function storeSmsCodeToCache($phone, $code, $minutes)
    {
        Cache::put("VC/$code", $phone, $minutes);  //以code为key缓存对应的phone,并设置code有效期
        Cache::put("HS/$phone", 1, 1);   //以phone为key缓存1分钟，控制发送频率
    }

    /**
     * 根据code从缓存中获取手机信息，用于验证码校验
     * @param  [type] $code [description]
     * @return  null|string    null-验证码错误/超时, 否则为手机号
     */
    public function getCodeFromCache($code){
        return Cache::get("VC/$code", null); //如果验证码存在，返回接收号码，否则返回0表示错误验证码
    }

    /**
     * 可以再次发送给目标phone
     * @param  [type] $phone 目标手机
     * @return bool        0-等待60s, 1-可立即发送
     */
    public function canSendAgainToPhone($phone){
        return ! Cache::has("HS/$phone");  //存在时表示前1min内刚发送过，所以返回0表示不能发送
    }
    /**
     * get sms data from session
     * @return mixed
     */
    public function getSmsDataFromSession()
    {
        return Session::get($this->getSessionKey(), []);
    }

    /**
     * remove sms data from session
     */
    public function forgetSmsDataFromSession()
    {
        Session::forget($this->getSessionKey());
    }

    /**
     * Is there a designated validation rule
     * 是否有指定的验证规则
     * @param $name
     * @param $ruleName
     *
     * @return bool
     */
    public function hasRule($name, $ruleName)
    {
        $data = $this->getSmsData();
        return isset($data['verify']["$name"]['rules']["$ruleName"]);
    }

    /**
     * get rule by name
     * @param $name
     *
     * @return mixed
     */
    public function getRule($name)
    {
        $data = $this->getSmsData();
        $ruleName = $data['verify']["$name"]['choose_rule'];
        return $data['verify']["$name"]['rules']["$ruleName"];
    }

    /**
     * set rule
     * @param $name
     * @param $value
     *
     * @return mixed
     */
    public function rule($name, $value)
    {
        $data = $this->getSmsData();
        $data['verify']["$name"]['choose_rule'] = $value;
        $this->setSmsData($data);
        return $data;
    }

    /**
     * is verify
     * @param string $name
     *
     * @return mixed
     */
    public function isCheck($name = 'mobile')
    {
        $data = $this->getSmsData();
        return $data['verify']["$name"]['enable'];
    }

    /**
     * get default and alternate agent`s verify sms template id
     * 获得默认/备用代理器的验证码短信模板id
     */
    public function getVerifySmsTemplateIdArray()
    {
        if ($this->isAlternateAgentsEnable()) {
            $agents = $this->getAlternateAgents();
            $defaultAgentName = $this->getDefaultAgent();
            if ( ! in_array($defaultAgentName, $agents)) {
                array_unshift($agents, $defaultAgentName);
            }
        } else {
            $agents[] = $this->getDefaultAgent();
        }
        $tempIdArray = [];
        foreach ($agents as $agentName) {
            $tempIdArray["$agentName"] = $this->getVerifySmsTemplateId($agentName);
        }
        return $tempIdArray;
    }

    /**
     * get verify sms template id
     * @param String $agentName
     * @return mixed
     */
    public function getVerifySmsTemplateId($agentName = null)
    {
        $agentName = $agentName ?: $this->getDefaultAgent();
        $agentConfig = config('laravel-sms.'.$agentName, null);
        if ($agentConfig && isset($agentConfig['verifySmsTemplateId'])) {
            return $agentConfig['verifySmsTemplateId'];
        }
        return '';
    }

    /**
     * get verify sms content
     * @return mixed
     */
    public function getVerifySmsContent()
    {
        return config('laravel-sms.verifySmsContent');
    }

    /**
     * generate verify code
     * @param null $length
     * @param null $characters
     *
     * @return string
     */
    public function generateCode($length = null, $characters = null)
    {
        $length = $length ?: (int) config('laravel-sms.codeLength');
        $characters = $characters ?: '123456789';
        $charLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; ++$i) {
            $randomString .= $characters[mt_rand(0, $charLength - 1)];
        }
        return $randomString;
    }

    /**
     * get code valid time (minutes)
     * @return mixed
     */
    public function getCodeValidTime()
    {
        return config('laravel-sms.codeValidTime');
    }

    /**
     * get session key
     * @param String $str
     * @return mixed
     */
    public function getSessionKey($str = '')
    {
        return config('laravel-sms.sessionKey') . $str;
    }

    /**
     * get the default agent name
     * @return mixed
     */
    public function getDefaultAgent()
    {
        return config('laravel-sms.agent');
    }

    /**
     * set the default agent name
     * @param $name
     * @return string
     */
    public function setDefaultAgent($name)
    {
        config(['laravel-sms.agent' => $name]);
        return config('laravel-sms.agent');
    }

    /**
     * get a agent instance
     * @param null $agentName
     *
     * @return mixed
     */
    public function agent($agentName = null)
    {
        $agentName = $agentName ?: $this->getDefaultAgent();
        if (! isset($this->agents[$agentName])) {
            $this->agents[$agentName] = $this->createAgent($agentName);
        }
        return $this->agents[$agentName];
    }

    /**
     * create a agent instance by agent name
     * @param $agentName
     *
     * @return mixed
     */
    public function createAgent($agentName)
    {
        $method = 'create'.ucfirst($agentName).'Agent';
        $agentConfig = $this->getAgentConfig($agentName);
        return $this->$method($agentConfig);
    }

    /**
     * get agent config
     * @param $agentName
     *
     * @return array
     */
    public function getAgentConfig($agentName)
    {
        $config = config("laravel-sms.$agentName", []);
        $config['smsSendQueue'] = config('laravel-sms.smsSendQueue', false);
        $config['smsWorker'] = config('laravel-sms.smsWorker', 'Toplan\Sms\SmsWorker');
        $config['nextAgentEnable'] = $this->isAlternateAgentsEnable();
        $config['nextAgentName'] = $this->getAlternateAgentNameByCurrentName($agentName);
        $config['currentAgentName'] = $agentName;
        if ( ! class_exists($config['smsWorker'])) {
            throw new \InvalidArgumentException("Worker [" . $config['smsWorker'] . "] not support.");
        }
        return $config;
    }

    /**
     * is alternate agents enable
     * return false or true
     * @return mixed
     */
    public function isAlternateAgentsEnable()
    {
        return config('laravel-sms.alternate.enable', false);
    }

    /**
     * get alternate agents name
     * @return mixed
     */
    public function getAlternateAgents()
    {
        return config("laravel-sms.alternate.agents", []);
    }

    /**
     * get alternate agent`s name
     * @param $agentName
     *
     * @return null
     */
    public function getAlternateAgentNameByCurrentName($agentName)
    {
        $agents = $this->getAlternateAgents();
        if ( ! count($agents)) {
            return null;
        }
        if ( ! in_array($agentName, $agents)) {
            return $agents[0];
        }
        if (in_array($agentName, $agents) && $agentName == $this->getDefaultAgent()) {
            return null;
        }
        $currentKey = array_search($agentName, $agents);
        if (($currentKey + 1) < count($agents)) {
            return $agents[$currentKey + 1];
        }
        return null;
    }

    /**
     * set can be send sms time
     * @param int $seconds
     *
     * @return int
     */
    public function setCanSendTime($seconds = 60)
    {
        $key = $this->getSessionKey('_CanSendTime');
        $time = time() + $seconds;
        // Session::put($key, $time);
        Cache::put($key, $time, 600);
        return $time;
    }

    /**
     * get can be send sms time
     * @return mixed
     */
    public function getCanSendTime()
    {
        $key = $this->getSessionKey('_CanSendTime');
        // return Session::get($key, 0);
        return Cache::get($key, 0);
    }

    /**
     * can be send sms
     * @return bool
     */
    public function canSend()
    {
        return $this->getCanSendTime() <= time();
    }

    /**
     * method overload
     * @param $name
     * @param $args
     *
     * @return mixed
     */
    public function __call($name, $args)
    {
        if (preg_match('/^(?:create)([0-9a-zA-Z]+)(?:Agent)$/', $name, $matches)) {
            $agentName = $matches[1];
            $className = 'Toplan\\Sms\\' . $agentName . 'Agent';
            if (class_exists($className)) {
                if (isset($args[0]) && is_array($args[0])) {
                    return new $className($args[0]);
                }
                throw new \InvalidArgumentException("Agent [$agentName] arguments cannot be empty, and must be array.");
            }
            throw new \InvalidArgumentException("Agent [$agentName] not support.");
        }
        throw new \BadMethodCallException("Method [$name] does not exist.");
    }
}
