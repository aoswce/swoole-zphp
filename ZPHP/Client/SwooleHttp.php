<?php
/**
 * Created by PhpStorm.
 * User: zhaoye
 * Date: 2016/12/26
 * Time: 上午9:35
 */

namespace ZPHP\Client;

use ZPHP\Controller\Controller;
use ZPHP\Core\App;
use ZPHP\Core\Db;
use ZPHP\Core\Dispatcher;
use ZPHP\Core\Factory;
use ZPHP\Core\Config;
use ZPHP\Core\Log;
use ZPHP\Core\Request;
use ZPHP\Core\Route;
use ZPHP\Core\Swoole;
use ZPHP\Coroutine\Base\CoroutineTask;
use ZPHP\Protocol\Response;
use ZPHP\Session\Session;
use ZPHP\Socket\Callback\SwooleHttp as ZSwooleHttp;
use ZPHP\Socket\IClient;

class SwooleHttp extends ZSwooleHttp
{
    /**
     * @var Dispatcher $dispatcher
     */
    protected $dispatcher;
    /**
     * @var CoroutineTask $coroutineTask
     */
    protected $coroutineTask;

    /**
     * @var Request $requestDeal;
     */
    protected $requestDeal;
    /**
     * @var Coroutine
     */
    public function onRequest($request, $response)
    {
        ob_start();
        try {
            if(strpos($request->server['path_info'],'.')!==false){
                throw new \Exception(403);
            }
            $requestDeal = clone $this->requestDeal;
            $requestDeal->init($request, $response);
            $httpResult = $this->dispatcher->distribute($requestDeal);
            if($httpResult!=='NULL') {
                if(!is_string($httpResult)){
                    if(strval(Config::getField('project','type'))=='api'){
                        $httpResult = json_encode($httpResult);
                    }else{
                        $httpResult = strval($httpResult);
                    }
                }
                $response->end($httpResult);
            }
        } catch (\Exception $e) {
            $message = explode('|',$e->getMessage());
            $code = intval($message[0]);
            if($code==0){
                $response->status(500);
                echo Swoole::info($e->getMessage());
            }else {
                $response->status($code);
                $otherMessage = !empty($message[1])?' '.$message[1]:'';
                echo Swoole::info(Response::$HTTP_HEADERS[$code].$otherMessage);
            }
        }
        $result = ob_get_contents();
        ob_end_clean();

        if(!empty($result)) {
            $response->end($result);
        }
    }



    /**
     * @param $server
     * @param $workerId
     * @throws \Exception
     */
    public function onWorkerStart($server, $workerId)
    {
        parent::onWorkerStart($server, $workerId);
        $common = Config::get('common_file');
        if(!empty($common)){
            require ROOTPATH.$common;
        }
        if (!$server->taskworker) {
            //worker进程启动协程调度器
            //work一启动加载连接池的链接、组件容器、路由
            Db::init($server, $workerId);
            App::init(Factory::getInstance(\ZPHP\Core\DI::class));
            Route::init();
            Session::init();
            $this->coroutineTask = Factory::getInstance(\ZPHP\Coroutine\Base\CoroutineTask::class);
            $this->dispatcher = Factory::getInstance(\ZPHP\Core\Dispatcher::class);
            $this->requestDeal = Factory::getInstance(\ZPHP\Core\Request::class, $this->coroutineTask);
        }
    }


    /**
     * @param $server
     * @param $workerId
     */
    public function onWorkerStop($server, $workerId){
        if(!$server->taskworker) {
            Db::getInstance()->freeMysqlPool();
            Db::getInstance()->freeRedisPool();
        }
        parent::onWorkerStop($server, $workerId);
    }

}
