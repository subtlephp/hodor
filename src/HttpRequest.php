<?php
/**
 * Created by PhpStorm.
 * User: Frost Wong <frostwong@gmail.com>
 * Date: 28/05/2017
 * Time: 22:20
 */

namespace Hodor;


use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Hodor\Exception\ApiNotSetException;
use Hodor\Exception\BaseUriNotSetException;
use Hodor\Exception\UriNotSetException;
use Hodor\HttpRequest\Middleware\Header;
use Hodor\HttpRequest\Middleware\Logger;
use Hodor\HttpRequest\Middleware\Replacer;
use Hodor\HttpRequest\Middleware\Retry;

abstract class HttpRequest
{
    use ErrorHandlerTrait;

    /**
     * Default retry options
     *
     * @var array
     */
    protected $retryOption = [
        'max' => 2,
        'delay' => 100,
    ];

    /**
     * Default guzzle options
     *
     * @var float
     */
    protected $defaultOptions = [
        'connect_timeout' => 2.0,
    ];


    /**
     * Service config, e.g base_uri
     *
     * @var array
     */
    protected $serviceConfig = [];
    protected $apiListConfig = [];

    /**
     * Set service level config
     *
     * @return null
     */
    abstract protected function setServiceConfig();

    /**
     * Set api config list
     *
     * @return null
     */
    abstract protected function setApiConfig();

    /**
     * Call remote service
     *
     * @param $apiName
     * @param array $options
     * @return mixed|null|\Psr\Http\Message\ResponseInterface
     * @throws ApiNotSetException
     * @throws BaseUriNotSetException
     * @throws UriNotSetException
     */
    public function call($apiName, array $options = [])
    {
        $this->setServiceConfig();
        $this->setApiConfig();

        if (!isset($this->apiListConfig[$apiName])) {
            throw new ApiNotSetException('api ' . $apiName . ' is not set yet');
        }
        $apiConfig = $this->apiListConfig[$apiName];

        if (!isset($this->serviceConfig['base_uri'])) {
            throw new BaseUriNotSetException('base_uri must be set');
        }

        $handlerStack = $this->getClientHandlerStack($apiName);
        $client = new Client([
            'base_uri' => $this->serviceConfig['base_uri'],
            'handler' => $handlerStack,
        ]);

        if (!isset($apiConfig['pattern'])) {
            throw new UriNotSetException('pattern for ' . $apiName . ' of service is not set yet');
        }

        $pattern = $apiConfig['pattern'];
        $request = new Request($client, $this->serviceConfig['method'], $pattern, $this->prepareHeaders());

        $response = $this->doSend($client, $request, $options);

        return $this->decode($response);
    }

    /**
     * Decode response, should be redefined if needed.
     *
     * @param ResponseInterface $response
     * @return mixed
     */
    protected function decode(ResponseInterface $response)
    {
        if (null === $response) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Composer handler stack for request
     *
     * @param $apiName
     * @return HandlerStack
     */
    private function getClientHandlerStack($apiName)
    {
        $apiRetryOption = isset($this->apiListConfig[$apiName]['retry']) ? $this->apiListConfig[$apiName]['retry'] : [];
        $serviceRetryOption = isset($this->serviceConfig['retry']) ? $this->serviceConfig['retry'] : [];

        $retryOption = $apiRetryOption + $serviceRetryOption + $this->retryOption;

        $middlewares = [
            Middleware::retry(Retry::decider($retryOption['max']), Retry::delay($retryOption['delay'])),
            Replacer::replaceHandler(),
            Header::commonHeaderHandler(),
            new Logger(),
        ];

        $handlerStack = HandlerStack::create();
        foreach ($middlewares as $middleware) {
            $handlerStack->push($middleware);
        }

        return $handlerStack;
    }

    /**
     * Send the request
     *
     * @param Client $client
     * @param Request $request
     * @param array $options
     * @return mixed|null|ResponseInterface
     */
    private function doSend(Client $client, Request $request, array $options = [])
    {
        try {
            $response = $client->send($request, $options + $this->defaultOptions);
        } catch (ClientException $e) {
            $this->writeToErrorLog($e);
            $response = $e->getResponse();
        } catch (ServerException $e) {
            $this->writeToErrorLog($e);
            $response = $e->getResponse();
        } catch (ConnectException $e) {
            $this->writeToErrorLog($e);
            $response = null;
        } catch (RequestException $e) {
            $this->writeToErrorLog($e);
            $response = $e->getResponse();
        } catch (Exception $e) {
            $this->writeToErrorLog($e);
            $response = null;
        }

        return $response;
    }

    /**
     * Prepare headers before sending a request
     *
     * @return array
     */
    private function prepareHeaders()
    {
        $serviceHeaders = isset($this->serviceConfig['headers']) ? $this->serviceConfig['headers'] : [];
        $apiHeaders = isset($apiConfig['headers']) ? $apiConfig['headers'] : [];
        return $apiHeaders + $serviceHeaders;
    }
}
