<?php
namespace Lightroom\Packager\Moorexa;

use Lightroom\Adapter\ClassManager;
use Lightroom\Exceptions\RequestManagerException;
use Lightroom\Router\Interfaces\RouterInterface;
use function Lightroom\Requests\Functions\server;
/**
 * @package Moorexa Router
 * @author Amadi Ifeanyi <amadiify.com>
 * 
 * The default router for moorexa controllers
 */
class Router implements RouterInterface
{
    use Helpers\RouterProperties, Helpers\RouterControls;

    /**
     * @var Router FIRST_PARAM
     */
    const FIRST_PARAM = 0;

    /**
     * @var Router SECOND_PARAM
     */
    const SECOND_PARAM = 1;

    /**
     * @var Router THIRD_PARAM
     */
    const THIRD_PARAM = 2;

    /**
     * @var RouterInterface $routerInstance
     */
    private static $routerInstance;

    /**
     * @method Router any
     * @param array $arguments
     * @return mixed
     */
    public static function any(...$arguments)
    {
        // get the last argument
        $lastArgument = end($arguments);

        // get the request method
        try {
            self::$requestMethod = server()->get('request_method', 'get');
        } catch (RequestManagerException $e) {}

        // create a closure function
        if (!isset($arguments[(int) self::SECOND_PARAM])) $arguments[(int) self::SECOND_PARAM] = function(){};

        // no closure function
        self::hasClosureFunction($arguments);

        // format args
        self::formatArgs($arguments);

        // save route called
        self::$routesCalled[self::$requestMethod][] = $arguments[(int) self::FIRST_PARAM];

        // execute route
        $router = call_user_func_array([static::class, 'executeRoute'], $arguments);

        if (is_string($lastArgument)) self::$closureUsed[$lastArgument] = $arguments[(int) self::SECOND_PARAM];

        // return mixed
        return $router;
    }

    /**
     * @method Router request
     * @param string $methods
     * @param mixed $match
     * @param mixed $call 
     * @return mixed
     */
	public static function request(string $methods, $match = null, $call = null)
	{
        // @var array $arguments
		$arguments = \func_get_args();

        // get the lastArgument
		$lastArgument = end($arguments);

		// split
		$methods = explode('|', $methods);

        // @var bool $valid
        $valid = false;
        
        // get request method
		$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

		foreach ($methods as $requestMethod) :
		
            if ($requestMethod != '' && strtolower($requestMethod) == strtolower($method)) :
            
                // request is valid
                $valid = true;
                break;

            endif;
            
		endforeach;

		if ($valid === true) :
		
            switch ($call === null) :

                // closure function found
                case false:

                    // update request matched
                    self::$requestMatch = [];

                    // format args
                    self::formatArgs($arguments);

                    // add method
                    $arguments[] = $method;

                    // execute route
                    $router = call_user_func_array([static::class, 'executeRoute'], $arguments);

                    // check for function call
                    if (is_string($lastArgument)) self::$closureUsed[$lastArgument] = $call;

                    return $router;
    
                // no closure function
                case true:

                    return call_user_func($match);

            endswitch;
			
		endif;
    }
    
    /**
     * @method Router __callStatic
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $method, array $arguments) 
    {
        // get request method
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD']);

        // @var mixed $router
        $router = null;

        // update method
        $method = strtoupper($method);

        // check if method is equivalent to the request method
        if ($method == $requestMethod) :

            // get the last argument
            $lastArgument = end($arguments);

            // get the first argument
            $firstArgument = $arguments[(int) self::FIRST_PARAM];

            // save route called
            self::$routesCalled[$method][] = $firstArgument;

            // check if it's string
            if (is_string($firstArgument)) :

                // check for function call
                if (is_string($lastArgument)) self::$closureName = $lastArgument;

                // no closure function
                self::hasClosureFunction($arguments);

                // format args
                self::formatArgs($arguments);

                // add method
                $arguments[] = $method;

                // execute route
                $router = call_user_func_array([static::class, 'executeRoute'], $arguments);

                // check for function call
                if (is_string($lastArgument)) :
                    
                    self::$closureUsed[$lastArgument] = $arguments[(int) self::SECOND_PARAM];

                endif;

            elseif (is_callable($firstArgument)) :

               // load closure
               call_user_func($firstArgument);

            endif;
                
        endif;

        // return mixed
        return $router == null ? ClassManager::singleton(Router::class) : $router;
    }

    /**
     * @method Router satisfy
     * @param string $route 
     * @param string $with
     * @return void
     */
    public static function satisfy(string $route, string $with) : void 
    {
        // @var array $routeArray
        $routeArray = explode('|', $route);

        // get route url
        $url = Router::$requestUri;

        // url string
        $urlString = implode('/', $url);

        // check route array
        foreach ($routeArray as $route) :

            // check route
            if ($route == $urlString) :

                // found
                Router::$requestUri = explode('/', $with);

                // break out
                break;

            else:

                foreach ($url as $index => $parameter) :

                    // match parameter
                    if ($parameter == $route) :

                        // found
                        $url[$index] = null;

                        // explode with
                        $with = explode('/', $with);

                        // splice value in
                        $before = array_splice($url, 0, $index);

                        // get url agein
                        $url = Router::$requestUri;

                        // get after the index
                        $after = array_splice($url, $index+1);

                        // combine all
                        Router::$requestUri = array_merge($before, $with, $after);

                        // break out
                        break;

                    endif;

                endforeach;

            endif;

        endforeach;
    }
}
