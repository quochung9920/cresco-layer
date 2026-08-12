(function(){
	'use strict';
	var config=window.crescoLayerAdmin||{};
	var select=document.getElementById('cresco-layer-document');
	if(!select)return;
	var patch=document.getElementById('cresco-layer-patch');
	var result=document.getElementById('cresco-layer-result');
	var status=document.getElementById('cresco-layer-status');
	var applyButton=document.getElementById('cresco-layer-apply');
	var previewedText='';

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

	function setStatus(text,tone){
		status.textContent=text||'';
		status.className=tone?'is-'+tone:'';
	}
	function endpoint(path){return String(config.restRoot||'').replace(/\/$/,'')+path;}
	function request(path,options){
		options=options||{};
		options.headers=Object.assign({'X-WP-Nonce':config.nonce,'Content-Type':'application/json'},options.headers||{});
		return fetch(endpoint(path),options).then(function(response){
			return response.json().catch(function(){return{};}).then(function(body){
				if(!response.ok){throw new Error(body&&body.message?body.message:'Request failed ('+response.status+')');}
				return body;
			});
		});
	}
	function documentId(){var id=parseInt(select.value||'0',10);if(!id)throw new Error('Choose an Elementor document first.');return id;}
	function clearResult(){while(result.firstChild)result.removeChild(result.firstChild);}
	function line(label,value){
		var row=document.createElement('div');row.className='cresco-layer-metric';
		var key=document.createElement('strong');key.textContent=label;
		var val=document.createElement('span');val.textContent=String(value);
		row.appendChild(key);row.appendChild(val);return row;
	}
	function renderAudit(audit){
		clearResult();
		var scores=(audit&&audit.scores)||{};var stats=(audit&&audit.stats)||{};
		var grid=document.createElement('div');grid.className='cresco-layer-score-grid';
		[['Accessibility',scores.accessibility],['Performance',scores.performance],['Design consistency',scores.designConsistency]].forEach(function(item){
			var card=document.createElement('div');card.className='cresco-layer-score';
			var value=document.createElement('strong');value.textContent=typeof item[1]==='number'?item[1]+'/100':'—';
			var label=document.createElement('span');label.textContent=item[0];card.appendChild(value);card.appendChild(label);grid.appendChild(card);
		});
		result.appendChild(grid);
		var metrics=document.createElement('div');metrics.className='cresco-layer-metrics';
		[['Elements',stats.nodes||0],['Max nesting',stats.maxDepth||0],['Images',stats.images||0],['Missing alt',stats.missingAlt||0],['Headings',stats.headings||0],['Local colors',stats.localColors||0]].forEach(function(item){metrics.appendChild(line(item[0],item[1]));});
		result.appendChild(metrics);
		var issues=(audit&&audit.issues)||[];
		var heading=document.createElement('h3');heading.textContent='Issues · '+issues.length;result.appendChild(heading);
		if(!issues.length){var good=document.createElement('p');good.textContent='No issues detected by the current Cresco audit rules.';result.appendChild(good);return;}
		var list=document.createElement('ul');list.className='cresco-layer-issues';
		issues.forEach(function(issue){var li=document.createElement('li');li.className='is-'+(issue.severity||'info');li.textContent=(issue.elementId?'#'+issue.elementId+' · ':'')+(issue.message||issue.code||'Issue');list.appendChild(li);});
		result.appendChild(list);
	}
	function renderPreview(data){
		clearResult();var diff=data.diff||{};
		var title=document.createElement('h3');title.textContent='Patch preview · '+(diff.total||0)+' operations';result.appendChild(title);
		var metrics=document.createElement('div');metrics.className='cresco-layer-metrics';
		[['Inserted',diff.inserted||0],['Removed',diff.removed||0],['Moved',diff.moved||0],['Updated',diff.updated||0],['Page settings',diff.pageSettings||0]].forEach(function(item){metrics.appendChild(line(item[0],item[1]));});
		result.appendChild(metrics);
		var checksum=document.createElement('p');checksum.className='description';checksum.textContent='Candidate checksum: '+(data.candidateChecksum||'');result.appendChild(checksum);
		var compare=document.createElement('div');compare.className='cresco-layer-audit-compare';
		var before=document.createElement('div');before.textContent='Accessibility before: '+((data.auditBefore&&data.auditBefore.scores&&data.auditBefore.scores.accessibility)||0);
		var after=document.createElement('div');after.textContent='Accessibility after: '+((data.auditAfter&&data.auditAfter.scores&&data.auditAfter.scores.accessibility)||0);
		compare.appendChild(before);compare.appendChild(after);result.appendChild(compare);
	}
	function parsePatch(){var text=patch.value.trim();if(!text)throw new Error('Paste a Cresco Layer patch first.');var parsed;try{parsed=JSON.parse(text);}catch(e){throw new Error('Patch is not valid JSON.');}return{parsed:parsed,text:text};}
	function downloadJson(filename,data){var blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});var url=URL.createObjectURL(blob);var a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},1000);}
	function busy(text){setStatus(text,'busy');}
	function failure(error){setStatus(error&&error.message?error.message:String(error),'error');}

	document.getElementById('cresco-layer-export').addEventListener('click',function(){
		try{var id=documentId();busy('Building AI-safe package…');request('/documents/'+id+'/export?scope=document').then(function(data){downloadJson('cresco-layer-'+id+'-ai-package.json',data);renderAudit(data.audit||{});setStatus('AI package exported.','success');}).catch(failure);}catch(e){failure(e);}
	});
	document.getElementById('cresco-layer-audit').addEventListener('click',function(){
		try{var id=documentId();busy('Auditing…');request('/documents/'+id+'/audit').then(function(data){renderAudit(data);setStatus('Audit complete.','success');}).catch(failure);}catch(e){failure(e);}
	});
	document.getElementById('cresco-layer-preview').addEventListener('click',function(){
		try{var id=documentId();var item=parsePatch();applyButton.disabled=true;previewedText='';busy('Validating patch…');request('/documents/'+id+'/preview',{method:'POST',body:JSON.stringify({patch:item.parsed})}).then(function(data){renderPreview(data);previewedText=item.text;applyButton.disabled=false;setStatus('Patch is valid. Review before applying.','success');}).catch(failure);}catch(e){failure(e);}
	});
	applyButton.addEventListener('click',function(){
		try{var id=documentId();var item=parsePatch();if(!previewedText||item.text!==previewedText){applyButton.disabled=true;throw new Error('Patch changed after preview. Validate it again before applying.');}if(!window.confirm('Apply this reviewed patch to the Elementor document? It will not publish the page.'))return;busy('Applying through Elementor…');applyButton.disabled=true;request('/documents/'+id+'/apply',{method:'POST',body:JSON.stringify({patch:item.parsed})}).then(function(data){renderAudit(data.audit||{});previewedText='';setStatus('Patch applied. Open Elementor, review, then Update/Publish when ready.','success');}).catch(function(error){applyButton.disabled=false;failure(error);});}catch(e){failure(e);}
	});
	patch.addEventListener('input',function(){if(patch.value.trim()!==previewedText)applyButton.disabled=true;});
})();
