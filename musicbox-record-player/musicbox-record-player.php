<?php
/**
 * Plugin Name: MusicBox 唱片播放器
 * Description: 单曲 / 歌单导入，JSON接口，缓存，防失败，随机或顺序播放，修复加载速度
 * Version: 3.3.6
 * Author: 码铃薯
 * Author URI: https://www.tudoucode.cn
 */

if (!defined('ABSPATH')) exit;

/* =========================
 * 设置注册
 * ========================= */
add_action('admin_init', function () {
    add_option('musicbox_song_list', '[]');
    add_option('musicbox_play_mode', 'random');
    register_setting('musicbox_options_group', 'musicbox_song_list');
    register_setting('musicbox_options_group', 'musicbox_play_mode');
});

/* =========================
 * 菜单
 * ========================= */
add_action('admin_menu', function () {
    add_options_page(
        'MusicBox 唱片播放器',
        'MusicBox 唱片播放器',
        'manage_options',
        'musicbox-settings',
        'musicbox_settings_page'
    );
});

/* =========================
 * 后台页面
 * ========================= */
function musicbox_settings_page() {
    $songs = get_option('musicbox_song_list', '[]');
    $mode  = get_option('musicbox_play_mode', 'random');
    $nonce = wp_create_nonce('musicbox_nonce');
?>
<div class="wrap">
<h2>🎵 MusicBox 唱片播放器</h2>

<form method="post" action="options.php">
<?php settings_fields('musicbox_options_group'); ?>

<h3>🎛️ 播放模式</h3>
<label>
<input type="radio" name="musicbox_play_mode" value="random" <?php checked($mode,'random'); ?>> 随机播放
</label>
<label style="margin-left:20px">
<input type="radio" name="musicbox_play_mode" value="order" <?php checked($mode,'order'); ?>> 顺序播放
</label>

<hr>

<h3>🎧 歌单导入（自动缓存 24h）</h3>
<p>
<input type="text" id="playlist-id" placeholder="网易云歌单 ID">
<button type="button" class="button" id="import-playlist">导入歌单</button>
</p>

<hr>

<h3>🎼 歌曲列表</h3>
<div id="musicbox-song-list"></div>
<button type="button" class="button" id="add-song">+ 添加单曲</button>

<input type="hidden" name="musicbox_song_list" id="musicbox_song_data"
       value="<?php echo esc_attr($songs); ?>">
<?php submit_button(); ?>

<button type="button" class="button" id="check-songs-valid">🎯 一键检测有效性</button>
<button type="button" class="button button-secondary" id="remove-invalid-songs">
🧹 删除所有失效歌曲
</button>

<hr>
<h3>🎨 封面本地化（插件目录）</h3>
<p>
    <button type="button" class="button button-primary" id="download-covers">🚀 一键下载封面</button>
</p>
<p style="font-size:12px;color:#999;margin-top:4px;">
    ⚠️ 请确保插件目录下 <code>covers/</code> 文件夹可写，否则封面无法保存
</p>
<div id="cover-log" style="margin-top:12px;font-family:monospace;"></div>

</form>
</div>

<style>
.musicbox-song-row{display:flex;gap:6px;align-items:center;margin-bottom:8px}
.musicbox-song-row input{width:150px}
.musicbox-preview{width:36px;height:36px;border-radius:50%;background:#111;overflow:hidden}
.musicbox-preview img{width:100%;height:100%;object-fit:cover}
.song-valid { font-size:12px; color:#0f0; margin-left:6px; }
.song-invalid { font-size:12px; color:#f00; margin-left:6px; }
</style>

<script>
var ajaxurl="<?php echo admin_url('admin-ajax.php'); ?>";
var musicboxNonce="<?php echo esc_js($nonce); ?>";

document.addEventListener('DOMContentLoaded',function(){
let list=document.getElementById('musicbox-song-list');
let hidden=document.getElementById('musicbox_song_data');
let songs=[];
try{songs=JSON.parse(hidden.value||'[]');if(!Array.isArray(songs))songs=[];}catch(e){songs=[];}

function sync(){hidden.value=JSON.stringify(songs);}

function render(){
    list.innerHTML='';
    songs.forEach((s,i)=>{
        let row=document.createElement('div');
        row.className='musicbox-song-row';
        row.innerHTML=`
        <input placeholder="网易云ID" value="${s.netease_id||''}">
        <input placeholder="MP3 URL" value="${s.url||''}">
        <input placeholder="封面 URL" value="${s.cover||''}">
        <button class="button auto">自动补全</button>
        <div class="musicbox-preview">${s.cover?`<img src="${s.cover}">`:''}</div>
        <button class="button del">删除</button>
        `;

        let inputs=row.querySelectorAll('input');
        let preview=row.querySelector('.musicbox-preview');

        inputs[0].oninput=e=>{s.netease_id=e.target.value;sync();}
        inputs[1].oninput=e=>{s.url=e.target.value;sync();}
        inputs[2].oninput=e=>{
            s.cover=e.target.value;
            preview.innerHTML=s.cover?`<img src="${s.cover}">`:'';
            sync();
        }

        // 恢复检测状态
        if (s._valid === true) {
            const span = document.createElement('span');
            span.textContent = '✅ 有效';
            span.className = 'song-valid';
            row.appendChild(span);
        } else if (s._valid === false) {
            const span = document.createElement('span');
            span.textContent = '❌ 无效';
            span.className = 'song-invalid';
            row.appendChild(span);
        }

        row.querySelector('.auto').onclick=()=>{
            if(!s.netease_id)return alert('请输入网易云ID');
            fetch(ajaxurl,{
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:new URLSearchParams({
                    action:'musicbox_fetch_single',
                    id:s.netease_id,
                    _ajax_nonce:musicboxNonce
                })
            })
            .then(r=>r.json()).then(d=>{
                if(!d||!d.url)return alert('获取失败');
                s.url=d.url;
                s.cover=d.cover;
                inputs[1].value=d.url;
                inputs[2].value=d.cover;
                preview.innerHTML=d.cover?`<img src="${d.cover}">`:'';
                sync();
            });
        };

        row.querySelector('.del').onclick=()=>{
            songs.splice(i,1);
            sync();
            render();
        };

        list.appendChild(row);
    });
}

document.getElementById('add-song').onclick=()=>{
    songs.push({});
    sync();
    render();
};

// 增量导入歌单
document.getElementById('import-playlist').onclick = () => {
    let id = document.getElementById('playlist-id').value;
    if (!id) return alert('请输入歌单ID');

    fetch(ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'musicbox_fetch_playlist',
            id: id,
            _ajax_nonce: musicboxNonce
        })
    })
    .then(r => r.json())
    .then(list2 => {
        if (!Array.isArray(list2)) return alert('导入失败');

        let added = 0;
        list2.forEach(n => {
            if (!songs.find(s => s.netease_id == n.netease_id)) {
                songs.push(n);
                added++;
            }
        });

        sync();
        render();
        alert(`增量导入完成，新增 ${added} 首歌曲`);
    });
};

// 一键检测有效性（修复版：使用同一个 songs）
document.getElementById('check-songs-valid').onclick = async () => {
    if(!songs.length) return alert('歌单为空，无法检测！');

    const rows = document.querySelectorAll('.musicbox-song-row');

    for(let index = 0; index < songs.length; index++){
        const row = rows[index];
        const idInput  = row.querySelector('input[placeholder="网易云ID"]');
        const urlInput = row.querySelector('input[placeholder="MP3 URL"]');

        let span = row.querySelector('.song-valid, .song-invalid');
        if(!span){
            span = document.createElement('span');
            row.appendChild(span);
        }

        span.textContent = '⏳ 检测中...';
        span.className = '';

        try {
            const res = await fetch(ajaxurl, {
                method:'POST',
                body:new URLSearchParams({
                    action:'musicbox_check_song',
                    _ajax_nonce: musicboxNonce,
                    id: idInput.value,
                    url: urlInput.value
                })
            });
            const json = await res.json();

            if(json.valid){
                span.textContent = '✅ 有效';
                span.className = 'song-valid';
                songs[index]._valid = true;
            } else {
                span.textContent = '❌ 无效';
                span.className = 'song-invalid';
                songs[index]._valid = false;
            }
        } catch(e) {
            span.textContent = '❌ 检测失败';
            span.className = 'song-invalid';
            songs[index]._valid = false;
        }
    }

    sync();
    render();
    alert('检测完成！');
};

// 删除所有失效歌曲
document.getElementById('remove-invalid-songs').onclick = () => {
    const before = songs.length;
    songs = songs.filter(s => s._valid !== false);
    const removed = before - songs.length;
    sync();
    render();
    alert(`已删除 ${removed} 首失效歌曲`);
};

render();
});

// 封面一键本地化
const coverLog = document.getElementById('cover-log');

function logCover(msg){
    coverLog.innerHTML += msg + "<br>";
    coverLog.scrollTop = coverLog.scrollHeight;
}

document.getElementById('download-covers').addEventListener('click', () => {
    if(!confirm('确认开始下载封面到插件目录吗？可能需要几秒钟~')) return;
    logCover('开始处理歌单...');

    fetch(ajaxurl, {
        method:'POST',
        body:new URLSearchParams({
            action:'musicbox_download_covers',
            _ajax_nonce: musicboxNonce
        })
    })
    .then(r=>r.json())
    .then(res=>{
        if(res.success){
            logCover(`✅ 完成！共处理 ${res.data.total} 首歌曲，下载 ${res.data.downloaded} 张封面。`);

            // —— 新增两行 —— //
            songs = res.data.songs || songs;          // 更新前端 songs 状态
            sync();                                   // 同步隐藏字段
            render();                                 // 刷新列表显示封面
        } else {
            logCover(`❌ 失败：${res.data}`);
        }
    })
    .catch(e=>logCover('❌ Ajax 错误：'+e));
});


</script>



<?php }

/* =========================
 * 封面一键本地化
 * ========================= */
add_action('wp_ajax_musicbox_download_covers', function() {
    check_ajax_referer('musicbox_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('权限不足');

    $songs = get_option('musicbox_song_list','[]');
    $songs = json_decode($songs,true);
    if(!$songs || !is_array($songs)) wp_send_json_error('歌单为空');

    // 插件根目录路径
    $plugin_dir = plugin_dir_path(__FILE__);
    $cover_dir  = $plugin_dir . 'covers/';
    if(!file_exists($cover_dir)) mkdir($cover_dir, 0755, true);

    $downloaded = 0;

    foreach($songs as &$song){
        if(empty($song['cover'])) continue;

        $url = $song['cover'];
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        $ext = in_array(strtolower($ext), ['jpg','jpeg','png','gif']) ? $ext : 'jpg';
        $filename = 'cover_'.$song['netease_id'].'.'.$ext;
        $filepath = $cover_dir . $filename;

        if(!file_exists($filepath)){
            $res = wp_remote_get($url, ['timeout'=>15]);
            if(!is_wp_error($res) && wp_remote_retrieve_response_code($res) == 200){
                file_put_contents($filepath, wp_remote_retrieve_body($res));
                $downloaded++;
            }
        } else {
            $downloaded++;
        }

        // 替换封面 URL 为本地插件目录路径
        $song['cover'] = plugins_url('covers/'.$filename, __FILE__);
    }

    update_option('musicbox_song_list', json_encode($songs));

    wp_send_json_success(['total'=>count($songs),'downloaded'=>$downloaded]);
});


/* =========================
 * Ajax：检测单首歌曲有效性
 * ========================= */
add_action('wp_ajax_musicbox_check_song', function() {
    check_ajax_referer('musicbox_nonce');

    if (!current_user_can('manage_options')) wp_die();

    $id  = intval($_POST['id'] ?? 0);
    $url = esc_url_raw($_POST['url'] ?? '');

    if (!$id && !$url) wp_send_json_error('缺少参数');

    // 优先用自定义 URL，否则构建网易云外链
    $mp3_url = $url ?: "https://music.163.com/song/media/outer/url?id={$id}.mp3";

    $res = wp_remote_head($mp3_url, [
        'timeout' => 10,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0',
            'Referer'    => 'https://music.163.com/'
        ],
        'redirection' => 5,
    ]);

    if (is_wp_error($res)) {
        wp_send_json(['valid' => false, 'msg' => '无法访问']);
    }

    $code  = wp_remote_retrieve_response_code($res);
    $ctype = wp_remote_retrieve_header($res, 'content-type');

    // 判断规则：200 + 音频类型
    if ($code == 200 && strpos($ctype, 'audio') !== false) {
        wp_send_json(['valid' => true, 'msg' => '有效']);
    } else {
        wp_send_json(['valid' => false, 'msg' => '无效']);
    }
});


/* =========================
 * Ajax：单曲自动补全（原逻辑 + nonce）
 * ========================= */
add_action('wp_ajax_musicbox_fetch_single', function () {
    check_ajax_referer('musicbox_nonce');
    if (!current_user_can('manage_options')) wp_die();
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_die();

    $api = "https://music.163.com/api/song/detail/?id={$id}&ids=[{$id}]";
    $res = wp_remote_get($api, [
        'timeout' => 10,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0',
            'Referer'    => 'https://music.163.com/'
        ]
    ]);
    if (is_wp_error($res)) wp_die();

    $json = json_decode(wp_remote_retrieve_body($res), true);
    if (empty($json['songs'][0])) wp_die();

    $song = $json['songs'][0];

    wp_send_json([
        'url'   => "https://music.163.com/song/media/outer/url?id={$id}.mp3",
        'cover' => $song['al']['picUrl'] ?? ''
    ]);
});

/* =========================
 * Ajax：歌单导入（原逻辑 + nonce）
 * ========================= */
add_action('wp_ajax_musicbox_fetch_playlist', function () {
    check_ajax_referer('musicbox_nonce');
    if (!current_user_can('manage_options')) wp_die();
    $pid = intval($_POST['id'] ?? 0);
    if (!$pid) wp_die();

    $key = "musicbox_playlist_{$pid}";
    if ($cached = get_transient($key)) wp_send_json($cached);

    $api = "https://music.163.com/api/playlist/detail?id={$pid}";
    $res = wp_remote_get($api, [
        'timeout' => 15,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0',
            'Referer'    => 'https://music.163.com/'
        ]
    ]);
    if (is_wp_error($res)) wp_die();

    $json = json_decode(wp_remote_retrieve_body($res), true);
    if (empty($json['result']['tracks'])) wp_die();

    $songs = [];
    foreach ($json['result']['tracks'] as $t) {
        $id = $t['id'] ?? 0;
        if (!$id) continue;
        $songs[] = [
            'netease_id' => $id,
            'url'   => "https://music.163.com/song/media/outer/url?id={$id}.mp3",
            'cover' => $t['album']['picUrl'] ?? ''
        ];
    }

    set_transient($key, $songs, DAY_IN_SECONDS);
    wp_send_json($songs);
});

/* =========================
 * 前端播放器（仅补全共享状态）
 * ========================= */
add_action('wp_footer', function () {
    $songs = json_decode(get_option('musicbox_song_list','[]'), true);
    if (!$songs || !is_array($songs)) return;
    $mode = get_option('musicbox_play_mode','random');
?>

<style>
#record-player {
    position: fixed;
    left: 10px;
    bottom: 10px;
    width: 50px;
    height: 50px;
    z-index: 9999;
    cursor: pointer;
}
#record {
    width: 100%;
    height: 100%;
    border-radius: 80%;
    background: radial-gradient(circle, #444 0%, #111 60%, #000 75%);
    position: relative;
}
#record.rotating {
    animation: spin 5s linear infinite;
}
.record-cover {
    position: absolute;
    width: 60%;
    height: 60%;
    top: 20%;
    left: 20%;
    border-radius: 50%;
    background-size: cover;
    background-position: center;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
@media (max-width:768px) {
    #record-player { display: none; }
}
</style>

<div id="record-player">
    <div id="record">
        <div class="record-cover"></div>
    </div>
</div>

<audio id="musicbox-audio" preload="none"></audio>

<!-- ========================
全局唯一状态源（核心修复点）
======================== -->
<script>
window.musicboxState = {
    songs: <?php echo json_encode(array_values($songs)); ?>,
    mode: "<?php echo esc_js($mode); ?>",
    index: ( "<?php echo esc_js($mode); ?>" === 'random'
        ? Math.floor(Math.random() * <?php echo count($songs); ?>)
        : 0
    )
};
</script>

<!-- ========================
主播放器模块（原逻辑 + 状态修复）
======================== -->
<script>
(() => {
    const state = window.musicboxState;
    const songs = state.songs;
    const mode  = state.mode;
    if (!songs.length) return;

    let hasUserInteracted = false;

    const audio  = document.getElementById('musicbox-audio');
    const record = document.getElementById('record');
    const cover  = document.querySelector('.record-cover');

function loadSong(i) {
    if (!songs[i]) return;
    state.index = i;
    audio.src = songs[i].url;

    // 懒加载封面：先显示空，加载完图片再显示
    cover.style.backgroundImage = '';  // 清空旧封面
    if (songs[i].cover) {
        const img = new Image();
        img.onload = () => {
            cover.style.backgroundImage = `url('${songs[i].cover}')`;
        }
        img.src = songs[i].cover;
    }
}
    //主动暴露歌源信息
    window.musicboxLoadSong = loadSong;
    
    loadSong(state.index);

    function next() {
        if (mode === 'random') {
            state.index = Math.floor(Math.random() * songs.length);
        } else {
            state.index = (state.index + 1) % songs.length;
        }
        loadSong(state.index);
        if (hasUserInteracted) {
            audio.play().catch(()=>{});
        }
    }

    record.addEventListener('click', () => {
        hasUserInteracted = true;
        if (audio.paused) {
            audio.play().then(() => {
                record.classList.add('rotating');
            }).catch(()=>{});
        } else {
            audio.pause();
            record.classList.remove('rotating');
        }
    });

    audio.addEventListener('ended', next);
    audio.addEventListener('error', next);
})();
</script>

<!-- ========================
音量 / 悬浮提示 / 双击切歌模块（原样保留 + 状态修复）
======================== -->
<script>
(() => {
    const audio  = document.getElementById('musicbox-audio');
    const record = document.getElementById('record');
    const state  = window.musicboxState;
    if (!audio || !record || !state.songs.length) return;

    const songs = state.songs;
    const mode  = state.mode;

    let isHovering = false;

    function nextSong() {
        if (mode === 'random') {
            state.index = Math.floor(Math.random() * songs.length);
        } else {
            state.index = (state.index + 1) % songs.length;
        }
        window.musicboxLoadSong(state.index);
        audio.play().catch(()=>{});
    }

    const tip = document.createElement('div');
    tip.style.position = 'fixed';
    tip.style.padding = '3px 8px';
    tip.style.background = 'linear-gradient(135deg, #ff9ec0, #ffb6c1)';
    tip.style.color = '#fff';
    tip.style.fontSize = '11px';
    tip.style.borderRadius = '8px';
    tip.style.pointerEvents = 'none';
    tip.style.whiteSpace = 'nowrap';
    tip.style.opacity = '0';
    tip.style.transition = 'opacity 0.2s, transform 0.1s';
    tip.style.fontWeight = 'bold';
    tip.style.textShadow = '0 1px 1px rgba(0,0,0,0.3)';
    tip.style.boxShadow = '0 1px 4px rgba(0,0,0,0.2)';
    document.body.appendChild(tip);

    const volBar = document.createElement('div');
    volBar.style.display = 'inline-block';
    volBar.style.verticalAlign = 'middle';
    volBar.style.width = '50px';
    volBar.style.height = '4px';
    volBar.style.background = 'rgba(255,255,255,0.3)';
    volBar.style.borderRadius = '2px';
    volBar.style.marginLeft = '6px';
    volBar.style.overflow = 'hidden';

    const volFill = document.createElement('div');
    volFill.style.height = '100%';
    volFill.style.background = '#ff69b4';
    volFill.style.borderRadius = '2px';
    volBar.appendChild(volFill);
    tip.appendChild(volBar);

    function showTip(extra = '') {
        tip.textContent = `(*≧ω≦)双击切歌+滚轮调音量 喵~${extra ? ' - ' + extra + '% 喵～' : ''}`;
        tip.appendChild(volBar);
        tip.style.opacity = '1';
        volFill.style.width = `${audio.volume * 100}%`;

        clearTimeout(tip._hideTimeout);
        tip._hideTimeout = setTimeout(() => {
            if (!isHovering) tip.style.opacity = '0';
        }, 1200);

        createNote();
    }

    record.addEventListener('mouseenter', () => {
        isHovering = true;
        const rect = record.getBoundingClientRect();
        tip.style.left = `${rect.right + 8}px`;
        tip.style.top = `${rect.top + rect.height / 3}px`;  //弹窗整体上调阀
        showTip();
    });

    record.addEventListener('mouseleave', () => {
        isHovering = false;
        tip.style.opacity = '0';
    });

    record.addEventListener('wheel', e => {
        e.preventDefault();
        audio.volume = Math.min(1, Math.max(0, audio.volume + (e.deltaY < 0 ? 0.05 : -0.05)));
        showTip(Math.round(audio.volume * 100));
    });

    record.addEventListener('dblclick', () => {
        nextSong();
        showTip('切歌成功！');
    });

    function createNote() {
        const note = document.createElement('div');
        note.textContent = '🎵';
        note.style.position = 'fixed';
        const rect = tip.getBoundingClientRect();
        note.style.left = `${rect.right}px`;
        note.style.top = `${rect.top}px`;
        note.style.fontSize = '12px';
        note.style.opacity = '1';
        note.style.pointerEvents = 'none';
        document.body.appendChild(note);

        note.animate([
            { transform: 'translateY(0)', opacity: 1 },
            { transform: 'translateY(-18px)', opacity: 0 }
        ], { duration: 500, easing: 'ease-out' });

        setTimeout(() => note.remove(), 500);
    }
})();
</script>

<!--播放器拖拽支持-->
<script>
(() => {
    const player = document.getElementById('record-player');
    if (!player) return;

    let isDragging = false;
    let startX = 0, startY = 0;
    let originX = 0, originY = 0;
    let moved = false;

    const DRAG_THRESHOLD = 5; // 像素阈值，防误触

    player.addEventListener('mousedown', e => {
        startX = e.clientX;
        startY = e.clientY;

        const rect = player.getBoundingClientRect();
        originX = rect.left;
        originY = rect.top;

        moved = false;
        isDragging = true;

        document.body.style.userSelect = 'none';
    });

    document.addEventListener('mousemove', e => {
        if (!isDragging) return;

        const dx = e.clientX - startX;
        const dy = e.clientY - startY;

        if (Math.abs(dx) > DRAG_THRESHOLD || Math.abs(dy) > DRAG_THRESHOLD) {
            moved = true;
        }

        if (!moved) return;

        player.style.left = originX + dx + 'px';
        player.style.top  = originY + dy + 'px';
        player.style.bottom = 'auto';
        player.style.right  = 'auto';
    });

    document.addEventListener('mouseup', () => {
        if (!isDragging) return;
        isDragging = false;
        document.body.style.userSelect = '';
    });

    // 阻止拖拽时触发点击
    player.addEventListener('click', e => {
        if (moved) {
            e.stopPropagation();
            e.preventDefault();
            moved = false;
        }
    });
})();
</script>

<!--首次打开弹窗小提示-->
<script>
setTimeout(() => {
    const audio  = document.getElementById('musicbox-audio');
    const record = document.getElementById('record');
    const state  = window.musicboxState;

    if (!audio || !record || !state || !state.songs.length) return;
    if (window.location.pathname !== '/') return;

    const tipKey = 'musicbox_home_tip_shown';
    const today  = new Date().toDateString();
    if (localStorage.getItem(tipKey) === today) return; // 今天已显示过

    // 创建提示气泡
    const tip = document.createElement('div');
    tip.style.position = 'fixed';
    tip.style.background = 'linear-gradient(135deg,#ff9ec0,#ffb6c1)';
    tip.style.color = '#fff';
    tip.style.padding = '4px 10px';
    tip.style.borderRadius = '12px';
    tip.style.fontSize = '12px';
    tip.style.fontWeight = 'bold';
    tip.style.textShadow = '0 1px 1px rgba(0,0,0,0.3)';
    tip.style.pointerEvents = 'auto';
    tip.style.whiteSpace = 'nowrap';
    tip.style.opacity = '0';
    tip.style.transition = 'opacity 0.3s, transform 0.2s';
    tip.style.zIndex = '99999';
    tip.textContent = '点击这里播放音乐 🎵';
    document.body.appendChild(tip);

    // 放在播放器右上方
    const rect = record.getBoundingClientRect();
    tip.style.left = rect.right + 8 + 'px';
    tip.style.top  = rect.top - 4 + 'px';

    // 显示动画
    requestAnimationFrame(() => {
        tip.style.opacity = '1';
        tip.style.transform = 'translateY(-5px)';
    });

    // 点击播放
    tip.addEventListener('click', () => {
        if (audio.paused) {
            audio.play().then(() => {
                record.classList.add('rotating');
            }).catch(()=>{});
        }
        tip.style.opacity = '0';
        setTimeout(() => tip.remove(), 500);
        localStorage.setItem(tipKey, today); // 标记今天已显示
    });

    // 自动消失
    setTimeout(() => {
        tip.style.opacity = '0';
        setTimeout(() => tip.remove(), 500);
        localStorage.setItem(tipKey, today); // 标记今天已显示
    }, 5000);
}, 5000); // 整体延迟 3 秒显示
</script>

<?php });
