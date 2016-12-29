<?php
/**
 * Created by PhpStorm.
 * User: zhaoye
 * Date: 16/7/16
 * Time: 下午2:52
 */


namespace ZPHP\Core;

use ZPHP\Coroutine\Redis\RedisAsynPool;
use ZPHP\Db\Mongo;
use ZPHP\Model\Model;
use ZPHP\Coroutine\Mysql\MysqlAsynPool;
use ZPHP\Redis\Redis;

class Db {
    /**
     * @var MysqlAsynPool
     */
    public $mysqlPool;

    /**
     * @var RedisAsynPool
     */
    public $redisPool;

    /**
     * @var RedisAsynPool
     */
    public $sessionRedisPool;

    public static $instance;
    protected static $db;
    protected static $_tables;
    protected static $_redis;
    protected static $_sessionRedis;
    protected static $_collection;
    private static $lastSql;
    private static $workId;

    private function __construct(){
        self::$instance = & $this;
    }

    /**
     * @return Db
     */
    public static function getInstance(){
        if(!isset(self::$instance)){
            self::$instance = new Db();
        }
        return self::$instance;
    }


    /**
     * @return workId
     */
    public static function getWorkId(){
        return self::$workId;
    }

    /**
     * @param $workId
     * 初始化mysql连接池
     */
    static public function initMysqlPool($workId, $config){
        if(!empty($config)) {
            if (empty(self::$instance->mysqlPool)) {
                self::$workId = $workId;
                self::$instance->mysqlPool = new MysqlAsynPool();
                self::$instance->mysqlPool->initWorker($workId, $config);
            }
        }
    }


    /**
     * 释放mysql连接池
     */
    static public function freeMysqlPool(){
        if(isset(self::$instance->mysqlPool)) {
            self::$instance->mysqlPool->free();
            unset(self::$instance->mysqlPool);
        }
    }

    /**
     * @param string $tableName
     * @param string $db_key
     * @return Model
     */
    public static function table($tableName='', $db_key = 'master'){
        if(!isset(self::$_tables[$tableName])){
            self::$_tables[$tableName] = new Model(self::$instance->mysqlPool);
            self::$_tables[$tableName]->table = $tableName;
        }
        return self::$_tables[$tableName];
    }

    /**
     * @param $workId
     */
    public static function initRedisPool($workId, $config){
        if(!empty($config)) {
            if (empty(self::$instance->redisPool)) {
                self::$instance->redisPool = new RedisAsynPool();
                self::$instance->redisPool->initWorker($workId, $config);
            }
        }
    }


    /**
     * 初始化
     * @param $workId
     * @throws \Exception
     */
    public static function initSessionRedisPool($workId, $config){
        if($config['enable'] && strtolower($config['adapter'])=='redis') {
            if (empty(self::$instance->sessionRedisPool)) {
                self::$instance->sessionRedisPool = new RedisAsynPool();
                $sRedisConf = $config['redis'];
                self::$instance->sessionRedisPool->initWorker($workId, $sRedisConf);
            }
        }
    }

    /**
     * free redis pool
     */
    static public function freeRedisPool(){
        if(isset(self::$instance->redisPool)) {
            self::$instance->redisPool->free();
            unset(self::$instance->redisPool);
        }
        if(isset(self::$instance->sessionRedisPool)) {
            self::$instance->sessionRedisPool->free();
            unset(self::$instance->sessionRedisPool);
        }
    }

    /**
     * @return Redis
     */
    public static function redis(){
        if(!isset(self::$_redis)){
            self::$_redis = new Redis(self::$instance->redisPool);
        }
        return self::$_redis;
    }


    /**
     * 用于session的redis连接池
     * @return Redis
     */
    public static function sessionRedis(){
        if(!isset(self::$_sessionRedis)){
            self::$_sessionRedis = new Redis(self::$instance->sessionRedisPool);
        }
        return self::$_sessionRedis;
    }


    /**
     * @param string $collectName
     * @return mixed
     * @throws \Exception
     */
    public static function collection($collectName = ''){
        if(!isset(self::$_collection[$collectName])){
            $config = Config::get('mongo');
            $host = 'mongodb://'.(!empty($config['username'])?"{$config['username']}":'')
                .(!empty($config['password'])?":{$config['password']}@":'')
                .$config['host'].(!empty($config['port'])?":{$config['port']}":'');
            $config['dsn'] = $host;
            $mongo = new Mongo();
            $mongo->connect($config);
            $mongo->setDBName($config['database']);
            $mongo->selectCollection($collectName);
            self::$_collection[$collectName] = $mongo;
            unset($mongo);
        }
        return self::$_collection[$collectName];
    }

    /**
     * pdo 查询获取pdo(同步)
     * @param string $db_key
     * @return mixed
     * @throws \Exception
     */
    public function getDb($db_key= 'master'){
        if(!isset(self::$db[$db_key])){
            $config = Config::getField('db', $db_key);
            if($config['type']=='pdo'){
                if(empty($config['persistent'])) {
                    self::$db[$db_key] = new \PDO($config['dsn'], $config['user'], $config['password']);
                }else{
                    self::$db[$db_key] = new \PDO($config['dsn'], $config['user'], $config['password'],
                        array(\PDO::ATTR_PERSISTENT => true));
                }
                if(!empty($config['charset'])){
                    self::$db[$db_key]->query('set names ' . $config['charset']);
                }
                self::$db[$db_key]->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            }
        }
        return self::$db[$db_key];
    }

    public static function setSql($sql){
        self::$lastSql = $sql;
    }

    public static function getLastSql(){
        return self::$lastSql;
    }




}