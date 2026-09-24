const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');

for(const file of ['assets/js/app.js','public/assets/js/app.js']){
  const code=fs.readFileSync(file,'utf8');

  test(`${file}: project cards are accessible project-detail triggers`,()=>{
    const view=code.slice(code.indexOf('function viewProjects'),code.indexOf('function filterProjectCards'));
    assert.match(view,/data-view-project=/);
    assert.match(view,/role="button" tabindex="0"/);
    const binding=code.split('\n').find(line=>line.includes("$$('[data-view-project]')"));
    assert.match(binding,/openProjectDetails/);
    assert.match(binding,/event\.target\.closest\('\.project-actions'\)/);
    assert.match(binding,/event\.key==='Enter'/);
  });

  test(`${file}: project detail includes summary, tasks, attachments, and task chats`,()=>{
    const detail=code.slice(code.indexOf('function openProjectDetails'),code.indexOf('async function deleteProject'));
    assert.match(detail,/project-detail-summary/);
    assert.match(detail,/project-detail-task/);
    assert.match(detail,/renderTaskAttachments\(task\.attachments\)/);
    assert.match(detail,/message\.taskId===task\.id/);
    assert.match(detail,/renderChat\(messages\)/);
    assert.match(detail,/data-project-detail-task/);
  });
}
