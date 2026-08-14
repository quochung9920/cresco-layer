(function(){
	'use strict';

	var config=window.crescoLayerAdmin||{};
	var select=document.getElementById('cresco-layer-document');
	if(!select)return;

	var contextProfile=document.getElementById('cresco-layer-context-profile');
	var patch=document.getElementById('cresco-layer-patch');
	var result=document.getElementById('cresco-layer-result');
	var status=document.getElementById('cresco-layer-status');
	var applyButton=document.getElementById('cresco-layer-apply');
	var catalogLoadButton=document.getElementById('cresco-layer-catalog-load');
	var catalogDownloadButton=document.getElementById('cresco-layer-catalog-download');
	var catalogQuery=document.getElementById('cresco-layer-catalog-query');
	var catalogResult=document.getElementById('cresco-layer-catalog-result');
	var catalogSummary=document.getElementById('cresco-layer-catalog-summary');
	var catalogStatus=document.getElementById('cresco-layer-catalog-status');
	var previewedText='';
	var catalogData=null;
	var catalogSearchTimer=null;
	var catalogDetailCache={};
	var catalogDetailPromises={};
	var app=document.getElementById('cresco-layer-app')||document.querySelector('.cresco-layer-admin');
	var toasts=document.getElementById('cresco-layer-toasts');
	var themeToggle=document.getElementById('cresco-layer-theme-toggle');
	var patchDrop=document.getElementById('cresco-layer-patch-drop');
	var patchFile=document.getElementById('cresco-layer-patch-file');
	var patchState=document.getElementById('cresco-layer-patch-state');
	var openEditorLink=document.getElementById('cresco-layer-open-editor');
	var patchStateTimer=null;

	(config.documents||[]).forEach(function(doc){
		var option=document.createElement('option');
		option.value=String(doc.id);
		option.textContent=doc.title+' · '+doc.type+' · #'+doc.id;
		select.appendChild(option);
	});
	if(!select.options.length){
		var empty=document.createElement('option');
		empty.value='';
		empty.textContent='No editable Elementor documents found';
		select.appendChild(empty);
	}

	function toast(text,tone){
		if(!toasts||!text)return;
		var item=document.createElement('div');
		item.className='cresco-layer-toast'+(tone?' is-'+tone:'');
		item.setAttribute('role','status');
		item.textContent=text;
		toasts.appendChild(item);
		while(toasts.children.length>4)toasts.removeChild(toasts.firstChild);
		setTimeout(function(){item.classList.add('is-leaving');setTimeout(function(){if(item.parentNode)item.parentNode.removeChild(item);},220);},tone==='error'?7000:4200);
	}
	function setStatus(text,tone){status.textContent=text||'';status.className=tone?'is-'+tone:'';if(tone==='success'||tone==='error')toast(text,tone);}
	function setCatalogStatus(text,tone){if(!catalogStatus)return;catalogStatus.textContent=text||'';catalogStatus.className=tone?'is-'+tone:'';}
	function endpoint(path){return String(config.restRoot||'').replace(/\/$/,'')+path;}
	function cleanServerMessage(text,statusCode){
		var clean=String(text||'').replace(/<script[\s\S]*?<\/script>/gi,' ').replace(/<style[\s\S]*?<\/style>/gi,' ').replace(/<[^>]+>/g,' ').replace(/&nbsp;/gi,' ').replace(/&quot;/gi,'"').replace(/&#039;/gi,"'").replace(/&amp;/gi,'&').replace(/\s+/g,' ').trim();
		if(/critical error/i.test(clean))return 'WordPress reported a critical PHP error while reading Elementor runtime data. Cresco stopped this request safely; check the WordPress/PHP error log for the originating addon.';
		return clean?clean.slice(0,320):'Request failed ('+statusCode+').';
	}
	function request(path,options){
		options=options||{};
		options.headers=Object.assign({'X-WP-Nonce':config.nonce,'Content-Type':'application/json'},options.headers||{});
		return fetch(endpoint(path),options).then(function(response){
			return response.text().then(function(text){
				var body={};
				if(text){try{body=JSON.parse(text);}catch(e){body={};}}
				if(!response.ok){throw new Error(body&&body.message?body.message:cleanServerMessage(text,response.status));}
				if(text&&!Object.keys(body).length&&text.trim()!=='{}')throw new Error('Cresco expected JSON but WordPress returned a non-JSON response. Check the PHP error log.');
				return body;
			});
		});
	}
	function documentId(){var id=parseInt(select.value||'0',10);if(!id)throw new Error('Choose an Elementor document first.');return id;}
	function selectedContextProfile(){var value=contextProfile?String(contextProfile.value||'smart'):'smart';return value==='full'?'full':'smart';}
	function clearNode(node){while(node&&node.firstChild)node.removeChild(node.firstChild);}
	function clearResult(){clearNode(result);}
	function line(label,value){var row=document.createElement('div');row.className='cresco-layer-metric';var key=document.createElement('strong');key.textContent=label;var val=document.createElement('span');val.textContent=String(value);row.appendChild(key);row.appendChild(val);return row;}
	function badge(text,tone){var el=document.createElement('span');el.className='cresco-layer-catalog-badge'+(tone?' is-'+tone:'');el.textContent=text;return el;}
	function pretty(value){try{return JSON.stringify(value,null,2);}catch(e){return String(value);}}
	function downloadJson(filename,data){var blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});var url=URL.createObjectURL(blob);var a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},1000);}

	function renderAudit(audit){
		clearResult();var scores=(audit&&audit.scores)||{};var stats=(audit&&audit.stats)||{};
		var grid=document.createElement('div');grid.className='cresco-layer-score-grid';
		[['Accessibility',scores.accessibility],['Performance',scores.performance],['Design consistency',scores.designConsistency]].forEach(function(item){var card=document.createElement('div');card.className='cresco-layer-score';var value=document.createElement('strong');value.textContent=typeof item[1]==='number'?item[1]+'/100':'—';var label=document.createElement('span');label.textContent=item[0];card.appendChild(value);card.appendChild(label);grid.appendChild(card);});
		result.appendChild(grid);
		var metrics=document.createElement('div');metrics.className='cresco-layer-metrics';
		[['Elements',stats.nodes||0],['Max nesting',stats.maxDepth||0],['Images',stats.images||0],['Missing alt',stats.missingAlt||0],['Headings',stats.headings||0],['Local colors',stats.localColors||0]].forEach(function(item){metrics.appendChild(line(item[0],item[1]));});result.appendChild(metrics);
		var issues=(audit&&audit.issues)||[];var heading=document.createElement('h3');heading.textContent='Issues · '+issues.length;result.appendChild(heading);
		if(!issues.length){var good=document.createElement('p');good.textContent='No issues detected by the current Cresco audit rules.';result.appendChild(good);return;}
		var list=document.createElement('ul');list.className='cresco-layer-issues';issues.forEach(function(issue){var li=document.createElement('li');li.className='is-'+(issue.severity||'info');li.textContent=(issue.elementId?'#'+issue.elementId+' · ':'')+(issue.message||issue.code||'Issue');list.appendChild(li);});result.appendChild(list);
	}

	function renderPreview(data){
		clearResult();var diff=data.diff||{};var semantic=data.semantic||{};var title=document.createElement('h3');title.textContent='Patch preview · '+(diff.total||0)+' operations';result.appendChild(title);
		var metrics=document.createElement('div');metrics.className='cresco-layer-metrics';[['Inserted',diff.inserted||0],['Removed',diff.removed||0],['Moved',diff.moved||0],['Updated',diff.updated||0],['Effective',typeof semantic.effectiveOperations==='number'?semantic.effectiveOperations:(diff.total||0)],['No-op',semantic.noOpOperations||0]].forEach(function(item){metrics.appendChild(line(item[0],item[1]));});result.appendChild(metrics);
		if(semantic.warnings&&semantic.warnings.length){var warningTitle=document.createElement('h3');warningTitle.textContent='Semantic warnings · '+semantic.warnings.length;result.appendChild(warningTitle);var warnings=document.createElement('ul');warnings.className='cresco-layer-issues';semantic.warnings.forEach(function(issue){var li=document.createElement('li');li.className='is-warning';li.textContent=(issue.elementId?'#'+issue.elementId+' · ':'')+(issue.setting?issue.setting+' · ':'')+(issue.message||issue.code||'Warning');warnings.appendChild(li);});result.appendChild(warnings);}
		var checksum=document.createElement('p');checksum.className='description';checksum.textContent='Candidate checksum: '+(data.candidateChecksum||'');result.appendChild(checksum);
		var compare=document.createElement('div');compare.className='cresco-layer-audit-compare';var before=document.createElement('div');before.textContent='Accessibility before: '+((data.auditBefore&&data.auditBefore.scores&&data.auditBefore.scores.accessibility)||0);var after=document.createElement('div');after.textContent='Accessibility after: '+((data.auditAfter&&data.auditAfter.scores&&data.auditAfter.scores.accessibility)||0);compare.appendChild(before);compare.appendChild(after);result.appendChild(compare);
	}

	function renderCatalogSummary(data){
		if(!catalogSummary)return;clearNode(catalogSummary);var counts=data.counts||{};var env=data.environment||{};var grid=document.createElement('div');grid.className='cresco-layer-score-grid cresco-layer-score-grid--catalog';
		[['Widgets',counts.widgets||0],['Element types',counts.elementTypes||0],['Widget controls',counts.widgetControls==null?'On demand':counts.widgetControls],['Element controls',counts.elementControls==null?'On demand':counts.elementControls]].forEach(function(item){var card=document.createElement('div');card.className='cresco-layer-score';var value=document.createElement('strong');value.textContent=String(item[1]);var label=document.createElement('span');label.textContent=item[0];card.appendChild(value);card.appendChild(label);grid.appendChild(card);});
		catalogSummary.appendChild(grid);var meta=document.createElement('div');meta.className='cresco-layer-catalog-meta';meta.appendChild(line('Elementor',env.elementorVersion||'—'));meta.appendChild(line('Elementor Pro',env.elementorProVersion||'Not detected'));meta.appendChild(line('WordPress',env.wordpressVersion||'—'));meta.appendChild(line('PHP',env.phpVersion||'—'));catalogSummary.appendChild(meta);catalogSummary.hidden=false;
	}

	function createJsonDetails(title,value,open){var details=document.createElement('details');details.className='cresco-layer-catalog-config';details.open=!!open;var summary=document.createElement('summary');summary.textContent=title;details.appendChild(summary);var pre=document.createElement('pre');pre.textContent=pretty(value);details.appendChild(pre);return details;}
	function controlSearchText(name,control){return [name,control&&control.type,control&&control.label,control&&control.description].filter(Boolean).join(' ').toLowerCase();}
	function detailKey(kind,name){return kind+':'+name;}
	function loadCatalogDetail(kind,name){
		var key=detailKey(kind,name);if(catalogDetailCache[key])return Promise.resolve(catalogDetailCache[key]);if(catalogDetailPromises[key])return catalogDetailPromises[key];
		catalogDetailPromises[key]=request('/elementor-catalog/'+kind+'/'+encodeURIComponent(name)).then(function(data){var entry=(data&&data.entry)||{};catalogDetailCache[key]=entry;delete catalogDetailPromises[key];return entry;}).catch(function(error){delete catalogDetailPromises[key];throw error;});
		return catalogDetailPromises[key];
	}
	function entryMatches(name,entry,kind,query){
		if(!query)return true;var q=query.toLowerCase();var base=[name,entry&&entry.name,entry&&entry.title,entry&&entry.className].concat((entry&&entry.categories)||[]).concat((entry&&entry.keywords)||[]).filter(Boolean).join(' ').toLowerCase();if(base.indexOf(q)!==-1)return true;
		var detail=catalogDetailCache[detailKey(kind,name)];var controls=(detail&&detail.controls)||{};return Object.keys(controls).some(function(controlName){return controlSearchText(controlName,controls[controlName]).indexOf(q)!==-1;});
	}
	function createControlDetails(name,control){var details=document.createElement('details');details.className='cresco-layer-control-item';var summary=document.createElement('summary');var title=document.createElement('span');title.className='cresco-layer-control-title';title.textContent=name;summary.appendChild(title);if(control&&control.type)summary.appendChild(badge(String(control.type),'neutral'));if(control&&control.responsive)summary.appendChild(badge('responsive','success'));if(control&&control.dynamic)summary.appendChild(badge('dynamic','info'));details.appendChild(summary);var built=false;details.addEventListener('toggle',function(){if(!details.open||built)return;built=true;var pre=document.createElement('pre');pre.textContent=pretty(control||{});details.appendChild(pre);});return details;}
	function renderEntryBody(container,entry){
		clearNode(container);var meta=document.createElement('div');meta.className='cresco-layer-catalog-entry-meta';if(entry&&entry.categories&&entry.categories.length)meta.appendChild(line('Categories',entry.categories.join(', ')));if(entry&&entry.keywords&&entry.keywords.length)meta.appendChild(line('Keywords',entry.keywords.join(', ')));meta.appendChild(line('Controls',(entry&&entry.controlCount)||Object.keys((entry&&entry.controls)||{}).length));container.appendChild(meta);
		var scanErrors=(entry&&entry.scanErrors)||[];if(scanErrors.length){var errors=document.createElement('ul');errors.className='cresco-layer-issues';scanErrors.forEach(function(issue){var li=document.createElement('li');li.className='is-warning';li.textContent=(issue.stage?issue.stage+' · ':'')+(issue.message||'Elementor runtime scan warning');errors.appendChild(li);});container.appendChild(errors);}
		var controls=(entry&&entry.controls)||{};var controlNames=Object.keys(controls).sort();var controlList=document.createElement('div');controlList.className='cresco-layer-control-list';controlNames.forEach(function(controlName){controlList.appendChild(createControlDetails(controlName,controls[controlName]));});if(!controlNames.length){var noControls=document.createElement('p');noControls.className='description';noControls.textContent=scanErrors.length?'This entry could not expose controls, but the rest of the catalog remains available.':'No controls exposed by this Elementor entry.';controlList.appendChild(noControls);}container.appendChild(controlList);container.appendChild(createJsonDetails('Default settings',entry&&entry.defaultSettings?entry.defaultSettings:{}));container.appendChild(createJsonDetails('Raw capability metadata',entry||{}));
	}
	function createCatalogEntry(name,entry,kind){
		var details=document.createElement('details');details.className='cresco-layer-catalog-item';var summary=document.createElement('summary');var heading=document.createElement('span');heading.className='cresco-layer-catalog-item__title';heading.textContent=(entry&&entry.title)||name;var code=document.createElement('code');code.textContent=name;var countBadge=badge('load on open','neutral');summary.appendChild(heading);summary.appendChild(code);summary.appendChild(countBadge);summary.appendChild(badge(kind,'widget'===kind?'info':'success'));details.appendChild(summary);
		var body=document.createElement('div');body.className='cresco-layer-catalog-item__body';body.hidden=true;details.appendChild(body);var requested=false;
		details.addEventListener('toggle',function(){if(!details.open||requested)return;requested=true;body.hidden=false;body.textContent='Loading controls from Elementor…';loadCatalogDetail(kind,name).then(function(full){countBadge.textContent=String((full&&full.controlCount)||Object.keys((full&&full.controls)||{}).length)+' controls';renderEntryBody(body,full);}).catch(function(error){clearNode(body);var warning=document.createElement('p');warning.className='cresco-layer-catalog-load-error';warning.textContent='Could not read this '+kind+': '+(error&&error.message?error.message:String(error))+'. Other catalog entries remain available.';body.appendChild(warning);countBadge.textContent='load failed';});});
		return details;
	}
	function renderCatalogCollection(title,kind,entries,query){
		var section=document.createElement('section');section.className='cresco-layer-catalog-group';var names=Object.keys(entries||{}).filter(function(name){return entryMatches(name,entries[name],kind,query);});names.sort(function(a,b){var aa=((entries[a]&&entries[a].title)||a).toLowerCase();var bb=((entries[b]&&entries[b].title)||b).toLowerCase();return aa.localeCompare(bb);});var head=document.createElement('div');head.className='cresco-layer-catalog-group__head';var h3=document.createElement('h3');h3.textContent=title+' · '+names.length;head.appendChild(h3);section.appendChild(head);if(!names.length){var empty=document.createElement('p');empty.className='description';empty.textContent='No matching '+title.toLowerCase()+'.';section.appendChild(empty);return section;}var list=document.createElement('div');list.className='cresco-layer-catalog-list';names.forEach(function(name){list.appendChild(createCatalogEntry(name,entries[name],kind));});section.appendChild(list);return section;
	}
	function renderCatalog(data,query){
		if(!catalogResult)return;clearNode(catalogResult);query=(query||'').trim();if(!query){var configGrid=document.createElement('div');configGrid.className='cresco-layer-catalog-config-grid';configGrid.appendChild(createJsonDetails('Active breakpoints',data.breakpoints||{},true));configGrid.appendChild(createJsonDetails('Active Kit / design system',data.activeKit||{},false));configGrid.appendChild(createJsonDetails('Catalog notes',data.notes||[],false));catalogResult.appendChild(configGrid);var topErrors=(data.scanErrors||[]);if(topErrors.length){var warning=document.createElement('ul');warning.className='cresco-layer-issues';topErrors.slice(0,20).forEach(function(issue){var li=document.createElement('li');li.className='is-warning';li.textContent=(issue.kind||'runtime')+(issue.name?' '+issue.name:'')+(issue.stage?' · '+issue.stage:'')+' · '+(issue.message||'Scan warning');warning.appendChild(li);});catalogResult.appendChild(warning);}}
		catalogResult.appendChild(renderCatalogCollection('Element types','element',data.elements||{},query));catalogResult.appendChild(renderCatalogCollection('Widgets','widget',data.widgets||{},query));
	}
	function renderCatalogSkeleton(){
		if(!catalogResult)return;clearNode(catalogResult);
		var skeleton=document.createElement('div');skeleton.className='cresco-layer-skeleton';skeleton.setAttribute('aria-hidden','true');
		for(var i=0;i<9;i++)skeleton.appendChild(document.createElement('span'));
		catalogResult.appendChild(skeleton);
	}
	function loadCatalog(){
		if(!catalogLoadButton)return;catalogLoadButton.disabled=true;catalogDetailCache={};catalogDetailPromises={};setCatalogStatus('Reading lightweight Elementor registry…','busy');renderCatalogSkeleton();
		request('/elementor-catalog').then(function(data){catalogData=data;renderCatalogSummary(data);renderCatalog(data,catalogQuery?catalogQuery.value:'');if(catalogDownloadButton)catalogDownloadButton.disabled=false;if(catalogQuery)catalogQuery.disabled=false;catalogLoadButton.textContent='Refresh Elementor catalog';setCatalogStatus('Catalog loaded. Open a widget or element to load its controls on demand.','success');}).catch(function(error){clearNode(catalogResult);var failed=document.createElement('p');failed.className='cresco-layer-catalog-load-error';failed.textContent='Could not load the Elementor catalog: '+(error&&error.message?error.message:String(error));catalogResult.appendChild(failed);setCatalogStatus(error&&error.message?error.message:String(error),'error');}).finally(function(){catalogLoadButton.disabled=false;});
	}
	function downloadFullCatalog(){
		if(!catalogData||!catalogDownloadButton)return;catalogDownloadButton.disabled=true;catalogLoadButton.disabled=true;var items=[];Object.keys(catalogData.elements||{}).forEach(function(name){items.push({kind:'element',name:name});});Object.keys(catalogData.widgets||{}).forEach(function(name){items.push({kind:'widget',name:name});});var full=Object.assign({},catalogData,{elements:{},widgets:{},downloadErrors:[]});var chain=Promise.resolve();
		items.forEach(function(item,index){chain=chain.then(function(){setCatalogStatus('Building full JSON '+(index+1)+'/'+items.length+'…','busy');return loadCatalogDetail(item.kind,item.name).then(function(entry){full[item.kind==='widget'?'widgets':'elements'][item.name]=entry;}).catch(function(error){var summary=(catalogData[item.kind==='widget'?'widgets':'elements']||{})[item.name]||{name:item.name};full[item.kind==='widget'?'widgets':'elements'][item.name]=Object.assign({},summary,{scanErrors:[{kind:item.kind,name:item.name,stage:'detail-request',message:error&&error.message?error.message:String(error)}]});full.downloadErrors.push({kind:item.kind,name:item.name,message:error&&error.message?error.message:String(error)});});});});
		chain.then(function(){downloadJson('cresco-layer-elementor-catalog.json',full);setCatalogStatus('Full catalog JSON built safely. Failed entries: '+full.downloadErrors.length+'.','success');}).catch(function(error){setCatalogStatus(error&&error.message?error.message:String(error),'error');}).finally(function(){catalogDownloadButton.disabled=false;catalogLoadButton.disabled=false;});
	}

	function parsePatch(){var text=patch.value.trim();if(!text)throw new Error('Paste a Cresco Layer patch first.');var parsed;try{parsed=JSON.parse(text);}catch(e){throw new Error('Patch is not valid JSON.');}return{parsed:parsed,text:text};}
	function busy(text){setStatus(text,'busy');}
	function failure(error){setStatus(error&&error.message?error.message:String(error),'error');}

	document.getElementById('cresco-layer-export').addEventListener('click',function(){try{var id=documentId();var profile=selectedContextProfile();busy('Building '+profile+' context AI-safe package…');request('/documents/'+id+'/export?scope=document&context='+encodeURIComponent(profile)).then(function(data){downloadJson('cresco-layer-'+id+'-'+profile+'-ai-package.json',data);renderAudit(data.audit||{});var stats=(data.contextResolver&&data.contextResolver.stats)||{};setStatus('AI package exported · '+profile+' context · '+(stats.detailedWidgets||0)+' widget types + '+(stats.detailedElements||0)+' element types expanded.','success');}).catch(failure);}catch(e){failure(e);}});
	document.getElementById('cresco-layer-audit').addEventListener('click',function(){try{var id=documentId();busy('Auditing…');request('/documents/'+id+'/audit').then(function(data){renderAudit(data);setStatus('Audit complete.','success');}).catch(failure);}catch(e){failure(e);}});
	document.getElementById('cresco-layer-preview').addEventListener('click',function(){try{var id=documentId();var item=parsePatch();applyButton.disabled=true;previewedText='';busy('Validating patch…');request('/documents/'+id+'/preview',{method:'POST',body:JSON.stringify({patch:item.parsed})}).then(function(data){renderPreview(data);previewedText=item.text;applyButton.disabled=false;setStatus('Patch is valid. Review before applying.','success');}).catch(failure);}catch(e){failure(e);}});
	applyButton.addEventListener('click',function(){try{var id=documentId();var item=parsePatch();if(!previewedText||item.text!==previewedText){applyButton.disabled=true;throw new Error('Patch changed after preview. Validate it again before applying.');}if(!window.confirm('Apply this reviewed patch to the Elementor document? It will not publish the page.'))return;busy('Applying through Elementor…');applyButton.disabled=true;request('/documents/'+id+'/apply',{method:'POST',body:JSON.stringify({patch:item.parsed})}).then(function(data){renderAudit(data.audit||{});previewedText='';setStatus(data.verification&&data.verification.verified===false?'Patch saved, but verification found mismatched operations. Review before publishing.':'Patch applied and saved to Elementor working data. Review, then Update/Publish when ready.',data.verification&&data.verification.verified===false?'error':'success');}).catch(function(error){applyButton.disabled=false;failure(error);});}catch(e){failure(e);}});
	patch.addEventListener('input',function(){if(patch.value.trim()!==previewedText)applyButton.disabled=true;});

	if(catalogLoadButton)catalogLoadButton.addEventListener('click',loadCatalog);
	if(catalogDownloadButton)catalogDownloadButton.addEventListener('click',downloadFullCatalog);
	if(catalogQuery)catalogQuery.addEventListener('input',function(){clearTimeout(catalogSearchTimer);catalogSearchTimer=setTimeout(function(){if(catalogData)renderCatalog(catalogData,catalogQuery.value);},120);});

	/* ---------- UI shell: tabs, theme, patch UX ---------- */

	function initTabs(){
		var tabs=Array.prototype.slice.call(document.querySelectorAll('[data-cresco-tab]'));
		var panels=Array.prototype.slice.call(document.querySelectorAll('[data-cresco-tab-panel]'));
		if(!tabs.length||!panels.length)return;
		function activate(name,persist){
			var known=tabs.some(function(tab){return tab.getAttribute('data-cresco-tab')===name;});
			if(!known)name=tabs[0].getAttribute('data-cresco-tab');
			tabs.forEach(function(tab){var active=tab.getAttribute('data-cresco-tab')===name;tab.classList.toggle('is-active',active);tab.setAttribute('aria-selected',active?'true':'false');});
			panels.forEach(function(panel){panel.hidden=panel.getAttribute('data-cresco-tab-panel')!==name;});
			if(persist){try{window.localStorage.setItem('crescoLayerAdminTab',name);}catch(e){}}
		}
		tabs.forEach(function(tab){tab.addEventListener('click',function(){activate(tab.getAttribute('data-cresco-tab'),true);});});
		var saved='';
		try{saved=window.localStorage.getItem('crescoLayerAdminTab')||'';}catch(e){}
		activate(saved||'exchange',false);
	}

	function initTheme(){
		if(!app)return;
		function apply(dark,persist){
			app.classList.toggle('is-dark',dark);
			if(themeToggle){
				themeToggle.setAttribute('aria-pressed',dark?'true':'false');
				var label=themeToggle.querySelector('.cresco-layer-theme-toggle__label');
				if(label)label.textContent=dark?'Light mode':'Dark mode';
			}
			if(persist){try{window.localStorage.setItem('crescoLayerAdminTheme',dark?'dark':'light');}catch(e){}}
		}
		var saved='';
		try{saved=window.localStorage.getItem('crescoLayerAdminTheme')||'';}catch(e){}
		apply(saved==='dark',false);
		if(themeToggle)themeToggle.addEventListener('click',function(){apply(!app.classList.contains('is-dark'),true);});
	}

	function describePatch(text){
		var trimmed=String(text||'').trim();
		if(!trimmed)return{label:'Empty',tone:'is-muted'};
		var parsed;
		try{parsed=JSON.parse(trimmed);}catch(e){return{label:'Invalid JSON',tone:'is-invalid'};}
		if(!parsed||typeof parsed!=='object'||Array.isArray(parsed))return{label:'Not a patch object',tone:'is-invalid'};
		if(parsed.schema!=='cresco-layer-patch/v1')return{label:'Unexpected schema',tone:'is-warning'};
		var ops=Array.isArray(parsed.operations)?parsed.operations.length:0;
		return{label:'Valid JSON · '+ops+' operation'+(ops===1?'':'s'),tone:'is-valid'};
	}
	function updatePatchState(){
		if(!patchState)return;
		var described=describePatch(patch.value);
		patchState.textContent=described.label;
		patchState.className='cresco-layer-chip '+described.tone;
	}
	function loadPatchFile(file){
		if(!file)return;
		if(file.size>8*1024*1024){toast('Patch file is larger than 8 MB — that is not a Cresco patch.','error');return;}
		var reader=new FileReader();
		reader.onload=function(){
			patch.value=String(reader.result||'');
			patch.dispatchEvent(new Event('input',{bubbles:true}));
			toast('Loaded '+file.name+' into the patch editor. Validate before applying.','info');
		};
		reader.onerror=function(){toast('Could not read '+file.name+'.','error');};
		reader.readAsText(file);
	}
	function initPatchUX(){
		patch.addEventListener('input',function(){clearTimeout(patchStateTimer);patchStateTimer=setTimeout(updatePatchState,140);});
		updatePatchState();
		patch.addEventListener('keydown',function(event){
			if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();document.getElementById('cresco-layer-preview').click();}
		});
		if(!patchDrop||!patchFile)return;
		patchDrop.addEventListener('click',function(){patchFile.click();});
		patchDrop.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();patchFile.click();}});
		patchFile.addEventListener('change',function(){loadPatchFile(patchFile.files&&patchFile.files[0]);patchFile.value='';});
		['dragenter','dragover'].forEach(function(name){patchDrop.addEventListener(name,function(event){event.preventDefault();patchDrop.classList.add('is-dragging');});});
		['dragleave','drop'].forEach(function(name){patchDrop.addEventListener(name,function(event){event.preventDefault();patchDrop.classList.remove('is-dragging');});});
		patchDrop.addEventListener('drop',function(event){var file=event.dataTransfer&&event.dataTransfer.files&&event.dataTransfer.files[0];loadPatchFile(file);});
	}

	function updateEditorLink(){
		if(!openEditorLink)return;
		var id=parseInt(select.value||'0',10);
		var template=String(config.elementorEditTemplate||'');
		if(!id||template.indexOf('__ID__')===-1){openEditorLink.hidden=true;return;}
		openEditorLink.href=template.replace('__ID__',String(id));
		openEditorLink.hidden=false;
	}

	initTabs();
	initTheme();
	initPatchUX();
	updateEditorLink();
	select.addEventListener('change',updateEditorLink);
})();
