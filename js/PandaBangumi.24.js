console.log('%c PandaBangumi 2.3 %c https://blog.imalan.cn/archives/128/ ', 'color: #fadfa3; background: #23b7e5; padding:5px 0;', 'background: #1c2b36; padding:5px 0;');

var bgmDirectCache = {};

function normalizeBgmSubject(item) {
    var subject = item.subject || {};
    var images = subject.images || {};
    var airWeekday = Number(subject.air_weekday || 0);
    if (!airWeekday && subject.date) {
        var date = new Date(subject.date + 'T00:00:00Z');
        var day = date.getUTCDay();
        airWeekday = day === 0 ? 7 : day;
    }
    return {
        name: subject.name || '',
        name_cn: subject.name_cn || '',
        url: subject.url || ('https://bgm.tv/subject/' + subject.id),
        status: Number(item.ep_status || 0),
        count: Number(subject.eps_count || subject.eps || subject.total_episodes || 0),
        air_weekday: airWeekday,
        img: (images.large || '').replace('http://', 'https://'),
        id: subject.id || 0
    };
}

function directCollections(type, cate) {
    var subjectType = type === 'watched' && cate === 'real' ? 6 : 2;
    var collectionType = type === 'watched' ? 2 : 3;
    var cacheKey = type + ':' + subjectType + ':' + collectionType;
    if (bgmDirectCache[cacheKey]) {
        return bgmDirectCache[cacheKey];
    }

    var url = bgmApiBase + '/users/' + encodeURIComponent(bgmUser) +
        '/collections?subject_type=' + subjectType +
        '&type=' + collectionType + '&limit=50&offset=0';
    bgmDirectCache[cacheKey] = fetch(url, {headers: {Accept: 'application/json'}})
        .then(function(response) {
            if (!response.ok) throw new Error('Bangumi API HTTP ' + response.status);
            return response.json();
        })
        .then(function(payload) {
            if (!payload || !Array.isArray(payload.data)) {
                throw new Error('Invalid Bangumi API response');
            }
            return payload.data.map(normalizeBgmSubject);
        });
    return bgmDirectCache[cacheKey];
}

function directCalendar(hideFinished) {
    return directCollections('watching', '').then(function(items) {
        var weekdays = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'];
        var result = weekdays.map(function(name) {
            return {weekday: {cn: name, ja: '', en: ''}, items: []};
        });
        items.forEach(function(item) {
            var count = Number(item.count || 0);
            if (hideFinished && count > 0 && Number(item.status || 0) >= count) return;
            var index = Number(item.air_weekday || 0) - 1;
            if (index >= 0 && index < 7) {
                result[index].items.push({
                    name: item.name,
                    name_cn: item.name_cn,
                    url: item.url,
                    img: item.img,
                    id: item.id
                });
            }
        });
        return result;
    });
}

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

    var params = new URLSearchParams({
        from: String(bgmCur),
        type: type
    });
    if (cate) {
        params.set('cate', cate);
    }
    var url = bgmBase + '?' + params.toString();
    var request = directCollections(type, cate).then(function(data) {
        var pageSize = typeof bgmPageSize === 'number' && bgmPageSize > 0 ? bgmPageSize : 1000000;
        return data.slice(bgmCur, bgmCur + pageSize);
    }).catch(function() {
        return $.getJSON(url);
    });
    request.then(function(data){
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
            url += '&filter=' + encodeURIComponent(filter);
        }
        if(hideFinished) {
            url += '&hideFinished=1';
        }
        
        directCalendar(hideFinished).catch(function() {
            return $.getJSON(url);
        }).then(function(data){
            $(item).html('');
            
            function decorateCalendarMessage(selector, extraClass) {
                var target = $(item).children(selector);
                target.addClass('bgm-calendar-message');
                if (extraClass) {
                    target.addClass(extraClass);
                }
            }
            
            if (!data || data.length === 0) {
                $(item).html('<div style="text-align:center;padding:20px;">暂无数据</div>');
                decorateCalendarMessage('[style*="text-align:center;padding:20px;"]');
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
            $(item).find('[style*="width:100%;text-align:center;padding:40px;color:#999;"]').addClass('bgm-calendar-message');
            
            $(item).find('.bgm-calendar-tab').on('click', function(){
                var dayIndex = $(this).attr('data-day');
                $(item).find('.bgm-calendar-tab').removeClass('active');
                $(item).find('.bgm-calendar-day').removeClass('active');
                $(this).addClass('active');
                $(item).find('.bgm-calendar-day[data-day="' + dayIndex + '"]').addClass('active');
            });
        }).catch(function(error) {
            console.error('Calendar load failed:', error);
            $(item).html('<div style="text-align:center;padding:20px;color:red;">加载失败，请刷新重试</div>');
            $(item).children('[style*="text-align:center;padding:20px;color:red;"]').addClass('bgm-calendar-message bgm-calendar-error');
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
