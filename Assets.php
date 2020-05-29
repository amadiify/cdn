// @var array $data
		$data = [];
		
		// callbacks
		$callbacks = [];
		
		// merge data
		foreach (self::$phpVarsData as $vars) :
			
			// add callback
			if (isset($vars['callback'])) $callbacks[] = $vars['callback'] . '.call();';
			
			// add export
			if (isset($vars['export'])) $callbacks[] = $vars['export'];
			
			// merge data 
			$data = array_merge($data, $vars);
			
		endforeach;
		
		// add callbacks
		if (count($callbacks) > 0) self::$jsScripts[] = '<script type="text/deffered">window.addEventListener("load", function(){ '.implode(' ', $callbacks).' });</script>';
		
		// return string 
		return '<script type="text/deffered">let phpvars = '.json_encode($data).';</script>';
