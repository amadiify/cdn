var addrowbutton = document.querySelector('#addtablerow');

if (addrowbutton != null)
{
    // get rows;
    var rows = document.querySelector('#rows');
    var tableRows = document.querySelectorAll('.table-row');

    // listen for click event
    addrowbutton.addEventListener('click', function(e){
        // push tr
        [].forEach.call(tableRows, function(e){
            var ele = document.createElement('tr');
            ele.innerHTML = e.innerHTML;
            var textCreate = e.querySelector('.text-create');
            if (textCreate != null)
            {
                ele.querySelector('td:nth-child(2)').innerHTML = textCreate.outerHTML;
            }
            var input = ele.querySelector('input'), textarea = ele.querySelector('textarea');
            if (input != null) { input.value = ''; }
            if (textarea != null) { textarea.value = ''; }
            rows.appendChild(ele);
        });

        require(['jquery','froala_editor'], function($, FroalaEditor) {

            // check for create
            var create = $('.text-create');
          
            if (create != null)
            {
              new FroalaEditor('.text-create', {
                height: 400,
                toolbarSticky: false
              });
            }
          
          });
    });
}