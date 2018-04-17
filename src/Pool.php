<?php

namespace ctbsea\phalapiSwoole;

/**
 * 连接池的操作
 *
 * Created by NetBeans.
 * Antor:  matyhtf
 * alert:  chentb
 * CreateTime: 2018/4/13 14:04
 * Description: 支持phalapi2.0 只有在连接数量不足以处理请求的时候才动态创建新的连接 
 *              并没有处理 高峰过后 删减连接数量
 * Versioncode: 2.0.0
 */
class Pool {

    /**
     * 连接池的尺寸，最大连接数
     * @var int $poolSize
     */
    protected $poolSize;

    /**
     * idle connection
     * @var array $resourcePool
     */
    protected $resourcePool = array();   //连接数组
    protected $resourceNum = 0;
    protected $failureCount = 0;

    /**
     * @var \SplQueue
     */
    protected $idlePool;

    /**
     * @var \SplQueue
     */
    protected $taskQueue;
    protected $createFunction;
    protected $config;

    /**
     * @param int $poolSize
     * @param array $config
     * @throws \Exception
     */
    public function __construct($config = array(), $poolSize = 100) {
        $this->poolSize = $poolSize;
        $this->taskQueue = new \SplQueue(); //任务队列
        $this->idlePool = new \SplQueue();  //空闲连接队列
        $this->config = $config;
    }

    /**
     * 加入到连接池中
     * @param $resource
     */
    function join($resource) {
        //保存到空闲连接池中
        $this->resourcePool[spl_object_hash($resource)] = $resource;
        $this->release($resource);
    }

    /**
     * 失败计数
     */
    function failure() {
        $this->resourceNum--;
        $this->failureCount++;
    }

    /**
     * @param $callback
     */
    function create($callback) {
        $this->createFunction = $callback;
    }

    /**
     * 修改连接池尺寸
     * @param $newSize
     */
    function setPoolSize($newSize) {
        $this->poolSize = $newSize;
    }

    /**
     * 移除资源
     * @param $resource
     * @return bool
     */
    function remove($resource) {
        $rid = spl_object_hash($resource);
        if (!isset($this->resourcePool[$rid])) {
            return false;
        }
        //从resourcePool中删除
        unset($this->resourcePool[$rid]);
        $this->resourceNum--;
        return true;
    }

    /**
     * 请求资源
     * @param callable $callback
     * @return bool
     */
    public function request(callable $callback) {
        //入队列
        $this->taskQueue->enqueue($callback);
        //有可用资源
        if (count($this->idlePool) > 0) {
            $this->doTask();
        }
        //没有可用的资源, 创建新的连接
        elseif (count($this->resourcePool) < $this->poolSize and $this->resourceNum < $this->poolSize) {
            call_user_func($this->createFunction);
            $this->resourceNum++;
        }
    }

    /**
     * 释放资源
     * 加入到空闲队列
     * 当某个连接处理了当前任务都会判断任务队列是否还有未处理的任务
     * 如果存在继续doTask
     * @param $resource
     */
    public function release($resource) {
        $this->idlePool->enqueue($resource);
        //有任务要做
        if (count($this->taskQueue) > 0) {
            $this->doTask();
        }
    }

    protected function doTask() {
        $resource = null;
        //从空闲队列中取出可用的资源
        while (count($this->idlePool) > 0) {
            $_resource = $this->idlePool->dequeue();
            $rid = spl_object_hash($_resource);
            //资源已经不可用了，连接已关闭
            if (!isset($this->resourcePool[$rid])) {
                continue;
            } else {
                //找到可用连接
                $resource = $_resource;
                break;
            }
        }
        //没有可用连接，如果没有达到最大连接池 创建达到继续等待
        if (!$resource) {
            if (count($this->resourcePool) == 0) {
                call_user_func($this->createFunction);
                $this->resourceNum++;
            }
            return;
        }
        //有空闲连接 处理任务
        $callback = $this->taskQueue->dequeue();
        call_user_func($callback, $resource);
    }

    /**
     * @return array
     */
    function getConfig() {
        return $this->config;
    }

}
