var IMPEncrypt={display:function(A){A=decodeURIComponent(A).evalJSON(true);this.action=A.action;this.params=A.params;this.uri=A.uri;var B=new Element("FORM",{action:"#",id:"RB_confirm"}).insert(new Element("P").insert(A.text)).insert(new Element("INPUT",{type:"text",size:15})).insert(new Element("INPUT",{type:"button",className:"button",value:A.ok_text}).observe("click",this._onClick.bind(this))).insert(new Element("INPUT",{type:"button",className:"button",value:A.cancel_text}).observe("click",this._close.bind(this))).observe("keydown",function(C){if((C.keyCode||C.charCode)==Event.KEY_RETURN){C.stop();this._onClick(C)}}.bind(this));RedBox.overlay=true;RedBox.onDisplay=Form.focusFirstElement.curry(B);RedBox.showHtml(B)},_close:function(){var A=RedBox.getWindowContents();[A,A.descendants()].flatten().compact().invoke("stopObserving");RedBox.close()},_onClick:function(A){params=this.params||{};params.passphrase=$F(A.findElement("form").down("input"));new Ajax.Request(this.uri,{parameters:params,onSuccess:this._onSuccess.bind(this),onFailure:this._onFailure.bind(this)})},_onSuccess:function(A){try{A=A.responseText.evalJSON(true)}catch(B){}if(A.response.success){this._close();if(this.action){this.action()}else{location.reload()}}else{if(A.response.error){alert(A.response.error)}}},_onFailure:function(A){}};