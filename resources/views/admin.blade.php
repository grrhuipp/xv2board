<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/custom.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/subscription-analysis.css?v={{$version}}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
<div id="root"></div>
<script src="/assets/admin/vendors.async.js?v={{$version}}"></script>
<script src="/assets/admin/components.async.js?v={{$version}}"></script>
<script src="/assets/admin/subscription-analysis.js?v={{$version}}"></script>
<script src="/assets/admin/umi.js?v={{$version}}"></script>
<script>
(function(){
    var sp = window.settings && window.settings.secure_path ? window.settings.secure_path : '';
    var srUrl = '/' + sp + '/smart-route';
    var injected = false;

    function inject(){
        if(injected) return;
        var ul = document.querySelector('ul.nav-main');
        if(!ul || !ul.children.length) return;
        if(ul.querySelector('[data-sr-menu]')) return;
        injected = true;

        var li = document.createElement('li');
        li.className = 'nav-main-item';
        li.setAttribute('data-sr-menu', '1');
        li.innerHTML = '<a class="nav-main-link" href="' + srUrl + '">'
            + '<i class="nav-main-link-icon si si-globe"></i>'
            + '<span class="nav-main-link-name">SmartRoute</span></a>';

        li.querySelector('a').addEventListener('click', function(e){
            e.preventDefault();
            window.open(srUrl, '_blank');
        });

        // Find the last item (horizon/queue monitor) and insert before it
        var allLi = ul.querySelectorAll(':scope > li');
        var horizonLi = null;
        for(var i = 0; i < allLi.length; i++){
            var span = allLi[i].querySelector('.nav-main-link-name');
            if(span && span.textContent.indexOf('\u961f\u5217') !== -1){
                horizonLi = allLi[i]; break;
            }
        }
        if(horizonLi){
            ul.insertBefore(li, horizonLi);
        } else {
            ul.appendChild(li);
        }
    }

    var t = setInterval(function(){
        inject();
        if(injected) clearInterval(t);
    }, 500);

    new MutationObserver(function(){
        if(!document.querySelector('[data-sr-menu]')){
            injected = false;
            inject();
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
</script>
</body>

</html>
