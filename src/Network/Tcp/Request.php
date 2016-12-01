<?php
/**
 * Created by IntelliJ IDEA.
 * User: winglechen
 * Date: 16/3/17
 * Time: 23:55
 */

namespace Zan\Framework\Network\Tcp;

use Thrift\Exception\TApplicationException;
use Zan\Framework\Contract\Network\Request as BaseRequest;
use Kdt\Iron\Nova\Nova;

class Request implements BaseRequest {
    private $data;
    private $route;
    private $serviceName;
    private $novaServiceName;
    private $methodName;
    private $args;
    private $fd;

    private $remoteIp;
    private $remotePort;
    private $fromId;
    private $seqNo;
    private $attachData;
    private $isHeartBeat = false;

    private $isGenericInvoke = false;
    private $genericServiceName;
    private $genericMethodName;
    private $genericRoute;
    private $genericAttachment = [];

    public function __construct($fd, $fromId, $data)
    {
        $this->fd = $fd;
        $this->fromId = $fromId;
        $this->data = $data;
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData($data)
    {
        $this->data = $data;
    }

    public function setFd($fd)
    {
        $this->fd = $fd;
    }

    public function getFd()
    {
        return $this->fd;
    }

    public function setRemote($ip, $port)
    {
        $this->remoteIp = $ip;
        $this->remotePort = $port;
    }

    public function setFromId($fromId)
    {
        $this->fromId = $fromId;
    }

    public function setSeqNo($seqNo)
    {
        $this->seqNo = $seqNo;
    }

    public function setAttachData($attachData)
    {
        $this->attachData = $attachData;
    }

    public function getAttachData()
    {
        return $this->attachData;
    }

    public function getRoute()
    {
        return $this->route;
    }

    public function getServiceName()
    {
        return $this->serviceName;
    }

    public function getNovaServiceName()
    {
        return $this->novaServiceName;
    }

    public function getMethodName()
    {
        return $this->methodName;
    }

    public function getArgs()
    {
        return $this->args;
    }

    public function getRemote()
    {
        return [
            'ip' =>$this->remoteIp,
            'port' => $this->remotePort,
        ];
    }

    public function getRemotePort()
    {
        return $this->remotePort;
    }

    public function getFromId()
    {
        return $this->fromId;
    }

    public function getSeqNo()
    {
        return $this->seqNo;
    }

    public function getIsHeartBeat()
    {
        return $this->isHeartBeat;
    }

    public function getGenericServiceName()
    {
        return $this->genericServiceName;
    }

    public function getGenericMethodName()
    {
        return $this->genericMethodName;
    }

    public function getGenericRoute()
    {
        return $this->genericRoute;
    }

    public function getGenericAttachment()
    {
        return $this->genericAttachment;
    }

    public function isGenericInvoke()
    {
        return $this->isGenericInvoke;
    }

    private function formatRoute()
    {
        $serviceName = ucwords($this->serviceName, '.');
        $this->novaServiceName = str_replace('.','\\',$serviceName);

        $path = '/'. str_replace('.', '/', $serviceName) . '/';
        $this->route = $path . $this->methodName;
    }

    private function decodeArgs()
    {
        $this->args = Nova::decodeServiceArgs(
            $this->novaServiceName,
            $this->methodName,
            $this->args
        );
    }

    public function decode() {
        $serviceName = $methodName = null;
        $remoteIP = $remotePort = null;
        $seqNo = $novaData = null;
        $attachData = $reqState = null;

        if (nova_decode($this->data, $serviceName, $methodName,
            $remoteIP, $remotePort, $seqNo, $attachData, $novaData)) {

            $this->serviceName = trim($serviceName);
            $this->methodName = trim($methodName);
            $this->args = $novaData;
            $this->remoteIp = $remoteIP;
            $this->remotePort = $remotePort;
            $this->seqNo = $seqNo;
            $this->attachData = $attachData;

            if('com.youzan.service.test' === $serviceName and 'ping' === $methodName) {
                $this->isHeartBeat = true;
                $data = null;
                nova_encode($this->serviceName, 'pong', $this->remoteIp, $this->remotePort, $this->seqNo, '', '', $data);
                return $data;
            }

            $this->isGenericInvoke = GenericRequestCodec::isGenericService($serviceName);
            if ($this->isGenericInvoke) {
                $this->initGenericInvoke($serviceName);
                return null;
            }

            $this->formatRoute();
            $this->decodeArgs();
        } else {
            throw new TApplicationException("nova_decode fail");
        }
    }

    private function initGenericInvoke($serviceName)
    {
        $this->novaServiceName = str_replace('.', '\\', ucwords($this->serviceName, '.'));
        $genericRequest = GenericRequestCodec::decode($this->novaServiceName, $this->methodName, $this->args);
        $this->genericServiceName = $genericRequest->serviceName;
        $this->genericMethodName = $genericRequest->methodName;
        $this->args = $genericRequest->methodParams;
        $this->route = '/'. str_replace('.', '/', $serviceName) . '/' . $this->methodName;
        $this->genericRoute = '/'. str_replace('\\', '/', $this->genericServiceName) . '/' . $this->genericMethodName;

        $this->initGenericAttachment();
    }

    private function initGenericAttachment()
    {
        $attachment = json_decode($this->attachData, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($attachment)) {
            return;
        }

        unset($attachment["async"]);

        foreach (GenericRequestCodec::$carmenInternalArgs as $carmenInternalArg) {
            if (isset($attachment[$carmenInternalArg])) {
                $this->genericAttachment[$carmenInternalArg] = $attachment[$carmenInternalArg];
                unset($attachment[$carmenInternalArg]);
            }
        }

        if (empty($attachment)) {
            $this->attachData =  "{}";
        } else {
            $this->attachData = json_encode($attachment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

}