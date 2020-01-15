var datapost = document.querySelectorAll("*[data-post]");
if(datapost.length > 0)
{
	[].forEach.call(datapost, function(dp){
		if(dp.nodeName.toLowerCase() == "form" || dp.hasAttribute("method") && dp.getAttribute("method").toLowerCase() == "post")
		{
			let postAttr = dp.getAttribute("data-post");
			let hiddenInput = document.createElement("input");
			hiddenInput.type = "hidden";
			hiddenInput.name = "postWrapper";
			hiddenInput.value = postAttr;
			dp.insertBefore(hiddenInput, dp.lastElementChild);
		}
	});
}

var __messagewrapper = document.querySelectorAll('.__message-wrapper');

if (__messagewrapper.length > 0)
{
	[].forEach.call(__messagewrapper, function(ele){
		setTimeout(function(){
			ele.style.visibility = "visible";
		},300);
	});	
}

// deferred text
function Event(ele, event, callback)
{
	if (ele.addEventListener)
	{
		ele.addEventListener(event, callback);
	}
	else if (ele.attachEvent)
	{
		ele.attachEvent(event, callback);
	}
	else if (ele['on'+event])
	{
		ele['on'+event] = callback;
	}
	else
	{
		return false;
	}
}


function hidealert(ele)
{
	var parent = ele.parentNode;
	parent.removeChild(ele);

	var pp = parent.parentNode;
	pp.removeChild(parent);
}

function loadalldata(){
	return {
		init : function()
		{
			// load images
			var datasrc = function(){
				var imgs = document.querySelectorAll('img');

				if (imgs.length > 0)
				{
					[].forEach.call(imgs, function(e){

						if(e.hasAttribute('data-src') && !e.hasAttribute('data-src-loaded'))
						{
							var src = e.getAttribute('data-src');
							e.src = src;
							e.setAttribute('data-src-loaded', true);
						}
					});
				}


				// javascripts
				var js = document.querySelectorAll('script');

				if (js.length > 0)
				{
					[].forEach.call(js, function(e){

						if(e.hasAttribute('data-src') && e.type != 'text/deffered' && !e.hasAttribute('data-src-loaded'))
						{
							var src = e.getAttribute('data-src');
							e.src = src;
							e.type = 'text/javascript';
							e.setAttribute('data-src-loaded', true);
						}
					});
				}
			}();



			// load javascripts deffered
			var textdeff = function(){
				var deffered = document.querySelectorAll('[type="text/deffered"]');
	
				if (deffered.length > 0)
				{
					[].forEach.call(deffered, function(def){

						if (!def.hasAttribute('data-deffered'))
						{
							var script = document.createElement("script");
							script.type = "text/javascript";

							if (def.hasAttribute("data-src"))
							{
								script.src = def.getAttribute("data-src");
							}
							else
							{
								if (def.hasAttribute("src"))
								{
									script.src = def.getAttribute("src");
								}
							}

							if (def.innerText.length > 0)
							{
								script.innerHTML = def.innerHTML;
							}
							
							def.setAttribute('data-deffered', 'true');

							script.async = def.hasAttribute("async");

							var body = document.body;
							body.insertBefore(script, body.lastElementChild.nextElementSibling);
						}	

					});
				}
			}();
		}
	}
}


loadalldata().init();