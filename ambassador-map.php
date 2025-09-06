<?php
/**
 * Plugin Name: Ambassador Map
 * Description: Interactive Leaflet map with clusters, search, tag chips, and a results panel. Shortcode: [ambassador_map]
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0+
 */

if ( ! defined('ABSPATH') ) exit;

/* ------------------------------------------------------------------------
 * Assets (Leaflet + MarkerCluster) 
 * --------------------------------------------------------------------- */
if ( ! function_exists('ghc_enqueue_leaflet_and_cluster') ) {
  function ghc_enqueue_leaflet_and_cluster() {
    wp_enqueue_style('leaflet-css','https://unpkg.com/leaflet/dist/leaflet.css',[],null);
    wp_enqueue_script('leaflet-js','https://unpkg.com/leaflet/dist/leaflet.js',[],null,true);

    wp_enqueue_style('markercluster-css','https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.css',[],null);
    wp_enqueue_style('markercluster-default-css','https://unpkg.com/leaflet.markercluster/dist/MarkerCluster.Default.css',[],null);
    wp_enqueue_script('markercluster-js','https://unpkg.com/leaflet.markercluster/dist/leaflet.markercluster.js',['leaflet-js'],null,true);
  }
  add_action('wp_enqueue_scripts','ghc_enqueue_leaflet_and_cluster');
}

/* -------------------------------------------
 * Shortcode: [ambassador_map]
 * ---------------------------------------- */
add_shortcode('ambassador_map', function () {

  // 1) Gather posts
  $ambassadors = get_posts([
    'post_type'      => 'ambassador',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
  ]);

  // 2) Gather taxonomy terms for chips (optional). Create taxonomy 'amb_tag' with CPT UI.
  $terms = get_terms(['taxonomy'=>'amb_tag','hide_empty'=>true]);
  $chips_html = '';
  if ( ! is_wp_error($terms) && $terms ) {
    foreach ($terms as $t) {
      $chips_html .= '<span class="amb-chip" data-term="'.esc_attr($t->slug).'">'.esc_html($t->name).'</span>';
    }
  }

  // 3) Build rows for JS (marker + popup + list card)
  $rows = [];
  foreach ($ambassadors as $a) {
    $lat = get_post_meta($a->ID,'latitude',true);
    $lng = get_post_meta($a->ID,'longitude',true);
    if (!$lat || !$lng) continue;

    $title      = get_the_title($a);
    $img_url    = get_the_post_thumbnail_url($a->ID,'medium');
    $content    = apply_filters('the_content',$a->post_content);
    $permalink  = get_permalink($a);
    $term_slugs = wp_get_post_terms($a->ID,'amb_tag',['fields'=>'slugs']);
    $term_names = wp_get_post_terms($a->ID,'amb_tag',['fields'=>'names']);

    // Popup HTML
    $popup  = '<div class="amb-popup">';
    if ($img_url) { $popup .= '<div class="amb-pop-img"><img src="'.esc_url($img_url).'" alt="'.esc_attr($title).'"></div>'; }
    $popup .= '<div class="amb-pop-body">';
    $popup .= '<h3 class="amb-pop-title">'.esc_html($title).'</h3>';
    $popup .= '<div class="amb-pop-content">'.$content.'</div>';
    $popup .= '<a class="amb-pop-link" href="'.esc_url($permalink).'">View full profile</a>';
    $popup .= '</div></div>';

    // List card HTML
    $card  = '<article class="amb-card-row" data-id="'.esc_attr($a->ID).'">';
    $card .= '<div class="amb-row-left">';
    if ($img_url) {
      $card .= '<img class="amb-row-img" src="'.esc_url($img_url).'" alt="'.esc_attr($title).'">';
    } else {
      $card .= '<div class="amb-row-img amb-row-img--placeholder">🌱</div>';
    }
    $card .= '</div><div class="amb-row-body">';
    $card .= '<h4 class="amb-row-title">'.esc_html($title).'</h4>';
    if ($term_names) {
      $card .= '<div class="amb-row-tags">';
      foreach ($term_names as $tn) { $card .= '<span class="amb-tag">'.esc_html($tn).'</span>'; }
      $card .= '</div>';
    }
    $card .= '<div class="amb-row-actions">';
    $card .= '<a href="'.esc_url($permalink).'" class="amb-btn">View profile</a>';
    $card .= '<button class="amb-btn amb-btn--ghost" data-focus="'.esc_attr($a->ID).'">Show on map</button>';
    $card .= '</div></div></article>';

    $rows[] = [
      'id'    => $a->ID,
      'lat'   => $lat,
      'lng'   => $lng,
      'title' => $title,
      'html'  => $popup,
      'card'  => $card,
      'text'  => wp_strip_all_tags($title.' '.$a->post_content),
      'terms' => $term_slugs,
    ];
  }

  // 4) JSON & assets to embed
  $rows_json = wp_json_encode($rows);
  $svg_data  = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="36" height="52" viewBox="0 0 36 52"><path d="M18 0c9.94 0 18 8.06 18 18 0 12.61-14.03 27.28-17.32 31a1 1 0 0 1-1.36 0C14.03 45.28 0 30.61 0 18 0 8.06 8.06 0 18 0z" fill="%236bb766"/><circle cx="18" cy="18" r="7" fill="white"/></svg>';

  // 5) CSS (scoped)
  $css = <<<CSS
  .amb-wrap{--leaf-50:#f5fbf2;--leaf-100:#eaf6e5;--leaf-200:#d8efd2;--leaf-600:#6bb766;--ink:#152219;--muted:#667b6c;--line:#cfe6c9;width:100vw;position:relative;left:50%;right:50%;margin-left:-50vw;margin-right:-50vw;background:linear-gradient(180deg,var(--leaf-100),var(--leaf-200));padding:24px 0 32px;}
  .amb-inner{max-width:1200px;margin:0 auto;padding:0 16px;}
  .amb-header{position:sticky;top:64px;z-index:401;margin-bottom:10px;}
  .amb-controls{display:flex;flex-direction:column;gap:10px;backdrop-filter:saturate(180%) blur(6px);}
  .amb-searchbar{display:flex;gap:10px;align-items:center;}
  .amb-input{flex:1;min-width:260px;padding:.9rem 1rem;border:1px solid var(--line);border-radius:14px;background:#fff;font-size:16px;color:var(--ink);outline:none;box-shadow:0 1px 2px rgba(0,0,0,.02);}
  .amb-input:focus{border-color:var(--leaf-600);box-shadow:0 0 0 3px rgba(107,183,102,.25);}
  .amb-chipbar{display:flex;flex-wrap:wrap;gap:8px;}
  .amb-chip{display:inline-flex;align-items:center;gap:6px;padding:.45rem .75rem;border:1px solid var(--line);border-radius:999px;background:#fff;cursor:pointer;font-size:14px;color:var(--ink);user-select:none;transition:all .15s ease;}
  .amb-chip:hover{transform:translateY(-1px);background:#f5fbf2;}
  .amb-chip.active{background:#6bb766;color:#072d13;border-color:transparent;}
  .amb-clear{margin-left:auto;display:none;}
  .amb-clear.show{display:inline-flex;}
  .amb-layout{display:grid;grid-template-columns:1.2fr .8fr;gap:14px;}
  #amb_map{width:100%;height:70vh;min-height:520px;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:#fff;}
  .amb-panel{background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 2px 8px rgba(7,35,16,.06),0 8px 24px rgba(7,35,16,.06);display:flex;flex-direction:column;min-height:70vh;}
  .amb-panel-head{padding:10px 12px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--line);}
  .amb-count{font-weight:600;color:var(--muted);}
  .amb-list{overflow:auto;padding:8px;display:grid;gap:8px;}
  .amb-card-row{display:grid;grid-template-columns:64px 1fr;gap:10px;padding:10px;border:1px solid var(--line);border-radius:14px;transition:box-shadow .15s ease,transform .06s;}
  .amb-card-row:hover{box-shadow:0 6px 20px rgba(7,35,16,.07);transform:translateY(-1px);}
  .amb-row-img{width:64px;height:64px;border-radius:12px;object-fit:cover;background:#f4f7f4;display:block;}
  .amb-row-img--placeholder{display:flex;align-items:center;justify-content:center;font-size:22px;color:#667b6c;}
  .amb-row-title{margin:2px 0 6px;font-size:16px;color:#152219;}
  .amb-row-tags{display:flex;flex-wrap:wrap;gap:6px;}
  .amb-tag{font-size:12px;padding:.2rem .5rem;border-radius:999px;background:#f5fbf2;border:1px solid var(--line);}
  .amb-row-actions{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;}
  .amb-btn{padding:.45rem .7rem;border-radius:10px;background:#6bb766;color:#07340f;border:0;font-weight:600;}
  .amb-btn--ghost{background:#fff;border:1px solid var(--line);color:#152219;}
  .amb-leaflet-popup .leaflet-popup-content{margin:0;}
  .amb-popup{width:360px;max-width:100%;overflow:hidden;font-size:14px;line-height:1.45;}
  .amb-pop-img img{display:block;width:100%;height:auto;}
  .amb-pop-body{padding:12px 14px 14px;}
  .amb-pop-title{margin:0 0 6px;font-size:16px;font-weight:700;color:#233026;}
  .amb-pop-content p{margin:0 0 8px;}
  .amb-pop-link{display:inline-block;margin-top:6px;text-decoration:underline;}
  @media (max-width:980px){.amb-layout{grid-template-columns:1fr;}#amb_map{min-height:420px}.amb-panel{min-height:auto}}
CSS;

  // 6) HTML shell (heredoc to keep snippet/plugin parser happy)
  $chips_section = $chips_html ? '<div id="amb_chips" class="amb-chipbar">'.$chips_html.'</div>' : '';

  $html_top = <<<HTML
  <style>{$css}</style>
  <div class="amb-wrap"><div class="amb-inner">
    <div class="amb-header">
      <div class="amb-controls">
        <div class="amb-searchbar">
          <input id="amb_search" class="amb-input" type="text" placeholder="Search ambassadors, skills, topics…">
          <button id="amb_clear" class="amb-chip amb-clear" type="button">Clear filters ✕</button>
        </div>
        {$chips_section}
      </div>
    </div>
    <div class="amb-layout">
      <div id="amb_map"></div>
      <aside class="amb-panel">
        <div class="amb-panel-head"><span class="amb-count"><span id="amb_count">0</span> result(s)</span></div>
        <div id="amb_list" class="amb-list"></div>
      </aside>
    </div>
  </div></div>
  <script>
  document.addEventListener('DOMContentLoaded', function(){
    var map = L.map('amb_map', { scrollWheelZoom:true }).setView([53.35,-6.26], 6);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { attribution: '&copy; OpenStreetMap, &copy; CARTO' }).addTo(map);

    var greenIcon = new (L.Icon.extend({ options: {
      iconUrl: '{$svg_data}',
      iconSize: [36,52], iconAnchor:[18,50], popupAnchor:[0,-44]
    }}))();

    var cluster = L.markerClusterGroup({ showCoverageOnHover:false, spiderfyOnMaxZoom:true });
    var all = [];
    var rows = {$rows_json};
HTML;

  $html_bottom = <<<HTML
    function fitAll(){
      if (cluster.getLayers().length){
        var b = cluster.getBounds(); if (b.isValid()) map.fitBounds(b,{padding:[28,28]});
      }
    }

    rows.forEach(function(item){
      var marker = L.marker([parseFloat(item.lat), parseFloat(item.lng)], { title:(item.title||''), icon: greenIcon })
        .bindPopup(item.html || ('<strong>'+ (item.title||'') +'</strong>'), { maxWidth:380, className:'amb-leaflet-popup' });
      cluster.addLayer(marker);
      all.push({ id:item.id, marker:marker, text:(item.text||'').toLowerCase(), terms:item.terms||[], card:item.card });
    });
    map.addLayer(cluster);
    fitAll();

    var listEl=document.getElementById('amb_list'), countEl=document.getElementById('amb_count');
    function renderList(items){
      listEl.innerHTML = items.map(m=>m.card).join('') || '<div style="padding:14px;color:#667b6c">No results.</div>';
      countEl.textContent = items.length;
    }
    renderList(all);

    var searchEl=document.getElementById('amb_search');
    var chipWrap=document.getElementById('amb_chips');
    var clearBtn=document.getElementById('amb_clear');
    var activeTags=new Set();

    function applyFilters(){
      var term=(searchEl.value||'').trim().toLowerCase();
      var anyFilter = term.length || activeTags.size;
      cluster.clearLayers();
      var matched=[];
      all.forEach(function(o){
        var textMatch=!term || o.text.includes(term);
        var tagMatch =!activeTags.size || o.terms.some(s=>activeTags.has(s));
        if(textMatch && tagMatch){ matched.push(o); cluster.addLayer(o.marker); }
      });
      renderList(matched);
      clearBtn.classList.toggle('show', anyFilter);
      if(matched.length){
        var gb=L.featureGroup(matched.map(m=>m.marker)).getBounds();
        if(gb.isValid()) map.fitBounds(gb,{padding:[35,35], maxZoom:12});
      } else { fitAll(); }
    }

    searchEl.addEventListener('input', applyFilters);
    if(chipWrap){
      chipWrap.addEventListener('click', function(e){
        var chip=e.target.closest('.amb-chip'); if(!chip) return;
        var slug=chip.getAttribute('data-term');
        chip.classList.toggle('active');
        chip.classList.contains('active') ? activeTags.add(slug) : activeTags.delete(slug);
        applyFilters();
      });
    }
    clearBtn.addEventListener('click', function(){
      searchEl.value=''; activeTags.clear();
      if(chipWrap) chipWrap.querySelectorAll('.amb-chip.active').forEach(c=>c.classList.remove('active'));
      applyFilters();
    });

    document.getElementById('amb_list').addEventListener('click', function(e){
      var btn=e.target.closest('[data-focus]'); if(!btn) return;
      var id=parseInt(btn.getAttribute('data-focus'),10);
      var m=all.find(x=>x.id===id); if(!m) return;
      map.setView(m.marker.getLatLng(), 13, {animate:true});
      setTimeout(()=>m.marker.openPopup(), 200);
    });

    setTimeout(()=>map.invalidateSize(), 250);
  });
  </script>
HTML;

  return $html_top . $html_bottom;
});
