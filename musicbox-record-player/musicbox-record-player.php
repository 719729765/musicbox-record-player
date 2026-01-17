<?php
/**
 * Plugin Name: MusicBox 唱片播放器（网易云稳定终版）
 * Description: 单曲 / 歌单导入，JSON接口，缓存，防失败，随机或顺序播放
 * Version: 3.2.0
 * Author: 码铃薯
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
</form>
</div>

<style>
.musicbox-song-row{display:flex;gap:6px;align-items:center;margin-bottom:8px}
.musicbox-song-row input{width:150px}
.musicbox-preview{width:36px;height:36px;border-radius:50%;background:#111;overflow:hidden}
.musicbox-preview img{width:100%;height:100%;object-fit:cover}
</style>

<script>
var ajaxurl="<?php echo admin_url('admin-ajax.php'); ?>";

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
inputs[2].oninput=e=>{s.cover=e.target.value;preview.innerHTML=s.cover?`<img src="${s.cover}">`:'';sync();}

row.querySelector('.auto').onclick=()=>{
if(!s.netease_id)return alert('请输入网易云ID');
fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
body:new URLSearchParams({action:'musicbox_fetch_single',id:s.netease_id})})
.then(r=>r.json()).then(d=>{
if(!d||!d.url)return alert('获取失败');
s.url=d.url;s.cover=d.cover;
inputs[1].value=d.url;inputs[2].value=d.cover;
preview.innerHTML=d.cover?`<img src="${d.cover}">`:'';
sync();
});
};

row.querySelector('.del').onclick=()=>{songs.splice(i,1);sync();render();}
list.appendChild(row);
});
}

document.getElementById('add-song').onclick=()=>{songs.push({});sync();render();}

document.getElementById('import-playlist').onclick=()=>{
let id=document.getElementById('playlist-id').value;
if(!id)return alert('请输入歌单ID');
fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
body:new URLSearchParams({action:'musicbox_fetch_playlist',id:id})})
.then(r=>r.json()).then(list2=>{
if(!Array.isArray(list2))return alert('导入失败');
songs=list2;sync();render();
});
};

render();
});
</script>
<?php }

/* =========================
 * Ajax：单曲自动补全（JSON接口）
 * ========================= */
add_action('wp_ajax_musicbox_fetch_single', function () {
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
 * Ajax：歌单导入（JSON接口 + 缓存）
 * ========================= */
add_action('wp_ajax_musicbox_fetch_playlist', function () {
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
 * 前端播放器（封面修复 + 尺寸修复）
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

<audio id="musicbox-audio" preload="auto"></audio>

<script>
(() => {
    const songs = <?php echo json_encode(array_values($songs)); ?> || [];
    const mode  = "<?php echo esc_js($mode); ?>";
    if (!songs.length) return;

    let index = 0;
    let hasUserInteracted = false;

    const audio = document.getElementById('musicbox-audio');
    const record = document.getElementById('record');
    const cover  = document.querySelector('.record-cover');

    // ------------------------
    // 初始化封面和预加载第一首歌
    // ------------------------
    audio.src = songs[index].url;
    cover.style.backgroundImage = `url('${songs[index].cover || ''}')`;

    // ------------------------
    // 加载歌曲（只设置 src，是否播放由点击控制）
    // ------------------------
    function loadSong(i) {
        if (!songs[i]) return;
        audio.src = songs[i].url;
        cover.style.backgroundImage = `url('${songs[i].cover || ''}')`;
    }

    function next() {
        if (!songs.length) return;
        if (mode === 'random') {
            index = Math.floor(Math.random() * songs.length);
        } else {
            index = (index + 1) % songs.length;
        }
        loadSong(index);
        if (hasUserInteracted) audio.play().catch(()=>{});
    }

    // ------------------------
    // 点击唱片播放 / 暂停
    // ------------------------
    record.addEventListener('click', () => {
        hasUserInteracted = true;

        if (!audio.src) loadSong(index);

        if (audio.paused) {
            audio.play().then(() => record.classList.add('rotating')).catch(()=>{});
        } else {
            audio.pause();
            record.classList.remove('rotating');
        }
    });

    // ------------------------
    // 音频结束 / 播放错误事件
    // ------------------------
    audio.addEventListener('ended', next);
    audio.addEventListener('error', next);
})();
</script>

<!--========
独立功能模块
============-->

<!--鼠标悬浮调节音量独立模块-->
<script>
(() => {
    const audio = document.getElementById('musicbox-audio');
    const record = document.getElementById('record');
    if (!audio || !record) return;

    let isHovering = false;

    // 当前播放索引
    let index = 0;
    const songs = window.musicboxSongs || []; // 确保全局有歌曲列表
    const mode = window.musicboxMode || 'random'; // 播放模式：random/order

    // ------------------------
    // 播放函数
    // ------------------------
    function loadSong(i, autoplay = true) {
        if (!songs[i]) return;
        audio.src = songs[i].url;
        if (autoplay) {
            audio.play().catch(() => {
                nextSong();
            });
        }
    }

    function nextSong() {
        if (!songs.length) return;
        if (mode === 'random') {
            index = Math.floor(Math.random() * songs.length);
        } else {
            index = (index + 1) % songs.length;
        }
        loadSong(index);
    }

    // ------------------------
    // 创建精致萌系提示弹窗
    // ------------------------
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

    // ------------------------
    // 创建小音量进度条
    // ------------------------
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
    volFill.style.width = `${audio.volume * 100}%`;
    volFill.style.height = '100%';
    volFill.style.background = '#ff69b4';
    volFill.style.borderRadius = '2px';
    volBar.appendChild(volFill);
    tip.appendChild(volBar);

    let baseTip = '(*≧ω≦) 滚轮调音量喵~';

    function showTip(extra = '') {
        tip.textContent = `${baseTip}${extra ? ' - ' + extra + '% 喵～' : ''}`;
        tip.appendChild(volBar);
        tip.style.opacity = '1';
        tip.style.transform = 'translateY(-50%) scale(1.05)';

        tip.animate([
            { transform: 'translateY(-50%) scale(1.05)' },
            { transform: 'translateY(-55%) scale(1.1)' },
            { transform: 'translateY(-50%) scale(1.05)' }
        ], { duration: 250, easing: 'ease-out' });

        volFill.style.width = `${audio.volume * 100}%`;

        clearTimeout(tip._hideTimeout);
        tip._hideTimeout = setTimeout(() => {
            if (!isHovering) tip.style.opacity = '0';
        }, 1200);

        createNote();
    }

    // ------------------------
    // 悬浮提示
    // ------------------------
    record.addEventListener('mouseenter', () => {
        isHovering = true;
        const rect = record.getBoundingClientRect();
        tip.style.left = `${rect.right + 8}px`;
        tip.style.top = `${rect.top + rect.height / 2}px`;
        showTip();
    });

    record.addEventListener('mouseleave', () => {
        isHovering = false;
        tip.style.opacity = '0';
    });

    // ------------------------
    // 滚轮调音量
    // ------------------------
    record.addEventListener('wheel', e => {
        e.preventDefault();
        audio.volume = Math.min(1, Math.max(0, audio.volume + (e.deltaY < 0 ? 0.05 : -0.05)));
        showTip(Math.round(audio.volume * 100));
    });

    // ------------------------
    // 双击切歌
    // ------------------------
    record.addEventListener('dblclick', () => {
        nextSong();
        showTip('切歌成功！');
    });

    // ------------------------
    // 小音符动画
    // ------------------------
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
            { transform: 'translateY(0) scale(1)', opacity: 1 },
            { transform: 'translateY(-18px) scale(1.1)', opacity: 0 }
        ], { duration: 500, easing: 'ease-out' });

        setTimeout(() => note.remove(), 500);
    }
})();
</script>

<!--随机歌曲+封面（仅展示，不播放）-->
<script>
(() => {
    const songs = <?php echo json_encode(array_values($songs)); ?> || [];
    if (!songs.length) return;

    const song = songs[Math.floor(Math.random() * songs.length)];
    const coverDiv = document.querySelector('.record-cover');
    if (coverDiv) coverDiv.style.backgroundImage = `url('${song.cover || ''}')`;
})();
</script>


<?php });

