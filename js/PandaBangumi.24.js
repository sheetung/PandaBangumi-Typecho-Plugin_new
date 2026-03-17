console.log('%c PandaBangumi 2.3 %c https://blog.imalan.cn/archives/128/ ', 'color: #fadfa3; background: #23b7e5; padding:5px 0;', 'background: #1c2b36; padding:5px 0;');

function loadMoreBgm(loader){
    if (loader === 'all') {
        // 加载页面上的全部面板
        $.each($('.loader'), function(i, item){
            loadMoreBgm(item);
        })
        return;
    }

    $(loader).html('<div class="dot"></div><div class="dot"></div><div class="dot"></div>');
    
    // 拼接 URL
    var listEl = $($(loader).attr('data-ref'));
    var bgmCur = listEl.attr('bgmCur');
    bgmCur = typeof bgmCur === 'string' ? parseInt(bgmCur) : 0;
    var type = listEl.attr('data-type');
    var cate = listEl.attr('data-cate');

    var url = bgmBase+'?from=' + String(bgmCur) + '&type=' + type + '&cate=' + cate;
    $.getJSON(url, function(data){
        $(loader).html('加载更多');
        if(data.length<1) $(loader).html('没有了');
        
        $.each(data, function (i, item) {
            var name_cn = item.name_cn ? item.name_cn : item.name;
            var status;
            var total;
            if(!item.count || item.count==null) {
                status=100;
                total='未知';
            }
            else {
                status = Math.min(100, item.status / item.count * 100); // 限制 status 在 0 到 100 之间
                total=String(item.count);
            };
            var html=`<a class="bgm-item" data-id="`+item.id+`" href="`+item.url+`" target="_blank">
                        <div class="bgm-item-thumb" style="background-image:url(`+item.img+`)"></div>
                        <div class="bgm-item-info">
                            <span class="bgm-item-title main">`+item.name+`</span>
                            <span class="bgm-item-title">`+name_cn+`</span>
                            {{status-bar}}
                        </div>
                    </a>`;
            if (type === 'watching') {
                html = html.replace('{{status-bar}}', `
                            <div class="bgm-item-statusBar-container">
                                <div class="bgm-item-statusBar" style="width:`+String(status)+`%"></div>
                                进度：`+String(item.status)+` / `+total+`
                            </div>`);
            } else {
                html = html.replace('{{status-bar}}', '');
            }
            listEl.append(html);

            bgmCur++;
        })

        // 记录当前数量
        listEl.attr('bgmCur', String(bgmCur));
    })
}

function initCollection(){
    var bgmIndex = 0;
    $.each($('.bgm-collection'), function(i, item) {
        bgmIndex++;
        $(item).attr('id', 'bgm-collection-' + String(bgmIndex));
        $(item).after(
                '<div class="loader" data-ref="' + '#bgm-collection-' + String(bgmIndex) + '" onclick="loadMoreBgm(this);"></div>');
    });

    loadMoreBgm('all');
}

function initCalendar(){
    $.each($('.bgm-calendar'), function(i, item) {
        $(item).html('<div class="dot"></div><div class="dot"></div><div class="dot"></div>');
        
        var filter = $(item).attr('data-filter');
        // 优先使用元素上的 data-hide-finished 属性，否则使用全局设置
        var hideFinishedAttr = $(item).attr('data-hide-finished');
        var hideFinished = hideFinishedAttr === 'true' || (hideFinishedAttr !== 'false' && typeof bgmHideFinished !== 'undefined' && bgmHideFinished);
        var url = bgmBase + '?type=calendar';
        if(filter) {
            url += '&filter=' + filter;
        }
        if(hideFinished) {
            url += '&hideFinished=1';
        }
        
        $.getJSON(url, function(data){
            $(item).html('');
            
            if (!data || data.length === 0) {
                $(item).html('<div style="text-align:center;padding:20px;">暂无数据</div>');
                return;
            }
            
            // 获取今天是周几 (0=周日, 1=周一, ... 6=周六)
            var today = new Date().getDay();
            // 转换为 Bangumi API 的格式 (1=周一, ... 7=周日)
            var todayWeekday = today === 0 ? 7 : today;
            // 转换为数组索引 (0=周一, ... 6=周日)
            var todayIndex = todayWeekday - 1;
            

            
            var calendarId = 'bgm-calendar-' + i;
            var tabsHtml = '<div class="bgm-calendar-tabs">';
            var contentHtml = '<div class="bgm-calendar-content">';
            
            $.each(data, function(index, day){
                // 默认选中今天
                var isActive = index === todayIndex ? 'active' : '';
                var itemCount = day.items && day.items.length ? day.items.length : 0;
                tabsHtml += '<div class="bgm-calendar-tab ' + isActive + '" data-day="' + index + '">' + day.weekday.cn + '(' + itemCount + ')</div>';
                
                contentHtml += '<div class="bgm-calendar-day ' + isActive + '" data-day="' + index + '">';
                if (day.items && day.items.length > 0) {
                    $.each(day.items, function(j, anime){
                        var name_cn = anime.name_cn ? anime.name_cn : anime.name;
                        contentHtml += '<a class="bgm-calendar-item" data-id="' + anime.id + '" href="' + anime.url + '" target="_blank"><div class="bgm-calendar-item-thumb" style="background-image:url(' + anime.img + ')"></div><div class="bgm-calendar-item-info"><span class="bgm-calendar-item-title main">' + anime.name + '</span><span class="bgm-calendar-item-title">' + name_cn + '</span></div></a>';
                    });
                } else {
                    contentHtml += '<div style="width:100%;text-align:center;padding:40px;color:#999;">今日无番剧</div>';
                }
                contentHtml += '</div>';
            });
            
            tabsHtml += '</div>';
            contentHtml += '</div>';
            
            $(item).append(tabsHtml + contentHtml);
            
            $(item).find('.bgm-calendar-tab').on('click', function(){
                var dayIndex = $(this).attr('data-day');
                $(item).find('.bgm-calendar-tab').removeClass('active');
                $(item).find('.bgm-calendar-day').removeClass('active');
                $(this).addClass('active');
                $(item).find('.bgm-calendar-day[data-day="' + dayIndex + '"]').addClass('active');
            });
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error('Calendar load failed:', textStatus, errorThrown);
            $(item).html('<div style="text-align:center;padding:20px;color:red;">加载失败，请刷新重试</div>');
        });
    });
}

$(document).ready(function(){
    initCollection();
    initCalendar();
})

$(document).on('pjax:complete', function () {
    initCollection();
    initCalendar();
})