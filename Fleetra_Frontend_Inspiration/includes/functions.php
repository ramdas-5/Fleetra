<?php
function icon(string $name, int $size=18): string {
  $paths = [
    'home'=>'<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-7h6v7"/>',
    'bus'=>'<rect x="4" y="3" width="16" height="15" rx="3"/><path d="M4 10h16M8 18v3m8-3v3M8 7h8"/><circle cx="8" cy="15" r="1"/><circle cx="16" cy="15" r="1"/>',
    'route'=>'<circle cx="6" cy="17" r="2"/><circle cx="18" cy="7" r="2"/><path d="M7.5 15.5c2.5-4 5 0 7-4s1.5-4 2-4.5"/>',
    'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    'ticket'=>'<path d="M3 7a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-3a2 2 0 0 0 0-4V7Z"/><path d="M13 5v14"/>',
    'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
    'chart'=>'<path d="M4 20V10m6 10V4m6 16v-7m4 7V7"/>',
    'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.1A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.1A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.4.3.74.64 1 .99.25.35.4.77.4 1.21V13c-.44 0-.86.15-1.2.4-.36.26-.7.6-1 .6-.23.33-.36.73-.2 1Z"/>',
    'wrench'=>'<path d="M14.7 6.3a4 4 0 0 0-5-5L12 3.6 9.6 6 7.3 3.7a4 4 0 0 0 5 5L4 17l3 3 8.3-8.3a4 4 0 0 0-.6-5.4Z"/>',
    'alert'=>'<path d="M12 3 2 21h20L12 3Z"/><path d="M12 9v5M12 18h.01"/>',
    'map'=>'<polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21 3 6"/><path d="M9 3v15M15 6v15"/>',
    'logout'=>'<path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 3h7v18h-7"/>',
    'search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    'plus'=>'<path d="M12 5v14M5 12h14"/>',
    'filter'=>'<path d="M4 6h16M7 12h10M10 18h4"/>',
    'more'=>'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
    'chevron'=>'<path d="m9 18 6-6-6-6"/>',
    'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'pin'=>'<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2"/>',
    'shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
    'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'card'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/>',
    'download'=>'<path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/>',
    'edit'=>'<path d="m4 20 4.5-1 10-10-3.5-3.5-10 10L4 20Z"/><path d="m13.5 6.5 3.5 3.5"/>',
    'eye'=>'<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
  ];
  $p=$paths[$name]??$paths['more'];
  return '<svg class="ico" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$p.'</svg>';
}

function stat_card($label,$value,$trend,$iconName='chart',$tone='green'){
  echo '<div class="stat-card"><div class="stat-icon '.$tone.'">'.icon($iconName,18).'</div><div class="stat-copy"><span>'.$label.'</span><strong>'.$value.'</strong><small>'.$trend.'</small></div><div class="spark"><i></i><i></i><i></i><i></i><i></i><i></i></div></div>';
}
function badge($text,$type='success'){ return '<span class="badge '.$type.'">'.$text.'</span>'; }
function avatar($name,$src=''){
  if($src) return '<img class="avatar" src="'.$src.'" alt="'.$name.'">';
  $parts=preg_split('/\s+/',trim($name)); $ini=''; foreach(array_slice($parts,0,2) as $p){$ini.=strtoupper($p[0]??'');}
  return '<span class="avatar initials">'.$ini.'</span>';
}
?>