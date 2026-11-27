"""Follow the converted user-list grant and a separately authorised native account administrator."""
import json
from pathlib import Path
import re

from delegation import authenticate
from http_board import submit_form


def browse(base, board, target, php):
    fixture = Path(__file__).with_suffix(".php")
    def stage(name):
        return php(fixture, target, name, cwd=board, capture=True)
    stage("source-limits")
    data = json.loads(stage("prepare"))
    ids = data["users"]
    admin = data["actors"]
    group = str(data["group"])
    mode = "&i=-phpbb2plus-community-acp-member_directory_module&mode="

    def state():
        return json.loads(stage("state"))
    def marks(page):
        return {int(k) for k in re.findall(r'data-selected-member="(\d+)"', page)}
    def values(page, key="bulk_action"):
        options = re.search(r'<select name="' + key + r'"[^>]*>(.*?)</select>', page, re.S)
        return set(re.findall(r'<option value="([^"]+)"', options.group(1))) if options else set()
    def review(actor, url, action, targets, group_id="0"):
        page = actor.fetch(url + "&show=200")
        if any('name="accounts[]" value="' + str(k) + '"' not in page for k in targets):
            raise RuntimeError("A selected fixture member was missing from the actual checkbox list")
        return submit_form(actor, url, page, "plus-directory-selection", {"bulk_action":action, "accounts[]":[str(k) for k in targets], "group":group_id})
    def apply(actor, url, page, status=200):
        return submit_form(actor, url, page, "plus-directory-confirm", {"confirm":"1"}, status)
    def changed(page, count, skipped=0):
        if "Changed: " + str(count) + "." not in page or "Skipped: " + str(skipped) + "." not in page:
            raise RuntimeError("Confirmed member action did not report the exact changed/skipped members")

    try:
        member, entry = authenticate(base, "MemberDirectoryJunior")
        url = entry + mode + "batch"
        page = member.fetch(url)
        if values(page) != {"activate","deactivate","ban","unban","group"} or group not in values(page,"group") or str(data["ordinary"]) in values(page,"group"):
            raise RuntimeError("Historical operator did not receive precisely the five actions and eligible group choices")
        if "plus-directory-selection" in member.fetch(entry + mode + "members"):
            raise RuntimeError("The reading view acquired a writable form")
        baseline = state()
        cancelled = review(member,url,"deactivate",[ids["One"]])
        if "Selection discarded." not in submit_form(member,url,cancelled,"plus-directory-confirm",{"cancel":"1"}) or \
           "This confirmation has expired" not in apply(member,url,cancelled) or state() != baseline:
            raise RuntimeError("Cancelling a selection retained a writable confirmation")
        selected = review(member,url,"deactivate",[ids["One"],ids["Two"],ids["Admin"],ids["Inactive"],admin["ModernMember"],admin["MemberDirectoryJunior"]])
        if marks(selected) != {ids["One"], ids["Two"]} or state() != baseline:
            raise RuntimeError("Preview changed accounts or selected a protected administrator, founder, self or already inactive user")
        stage("restricted")
        changed(apply(member,url,selected),1,1)
        if int(state()["users"][str(ids["Two"])]["user_type"]) != 1 or int(state()["users"][str(ids["One"])]["user_type"]) != 0:
            raise RuntimeError("A newly protected administrator was changed after review")
        stage("ordinary")
        if "This confirmation has expired" not in apply(member,url,selected):
            raise RuntimeError("A used selection was accepted again")
        page=review(member,url,"activate",[ids["Two"],ids["Inactive"]])
        changed(apply(member,url,page),2)
        selected=review(member,url,"ban",[ids[k] for k in ("One","Two","Original","Restricted","Exception","Mixed")])
        if marks(selected) != {ids["One"]}:
            raise RuntimeError("A scoped ban would replace an existing rule or exclusion")
        stage("deny-ban")
        apply(member,url,selected,403)
        if state()["bans"] != baseline["bans"]: raise RuntimeError("An open confirmation survived a revoked ban grant")
        stage("allow-ban")
        changed(apply(member,url,review(member,url,"ban",[ids["One"]])),1)
        after=state()
        if sum(int(b["plus_dir_ban"]) for b in after["bans"]) != 1 or not all(b in after["bans"] for b in baseline["bans"]):
            raise RuntimeError("A new list ban replaced existing rules")
        selected=review(member,url,"unban",[ids[k] for k in ("One","Two","Original","Restricted","Exception","Mixed")])
        if marks(selected) != {ids["One"],ids["Original"],ids["Mixed"]}: raise RuntimeError("Unban selected the wrong set of removable account rules")
        changed(apply(member,url,selected),3)
        now=state()
        if now["bans"] != [b for b in baseline["bans"] if not int(b["plus_card_legacy"])] or \
           int(now["users"][str(ids["Original"])]["plus_card_count"]) or int(now["users"][str(ids["Original"])]["user_warnings"]) != 2 or \
           now["users"][str(ids["Mixed"])]["plus_card_count"] != baseline["users"][str(ids["Mixed"])]["plus_card_count"] or \
           now["users"][str(ids["One"])]["plus_card_count"] != baseline["users"][str(ids["One"])]["plus_card_count"]:
            raise RuntimeError("The unban changed specific rules, native warnings or unrelated historical counts")
        selected=review(member,url,"group",[ids["One"],ids["Pending"]],group)
        stage("group-protect")
        if "This group is unavailable" not in apply(member,url,selected) or state()["members"] != baseline["members"]:
            raise RuntimeError("A group changed to founder-managed after review was still edited")
        stage("group-restore")
        changed(apply(member,url,review(member,url,"group",[ids["One"],ids["Pending"]],group)),2)
        if state()["members"] != {str(ids["One"]):"0",str(ids["Pending"]):"0"} and state()["members"] != {str(ids["One"]):0,str(ids["Pending"]):0}:
            raise RuntimeError("Approved and newly added group members did not become ordinary active members")
        if marks(review(member,url,"group",[ids["One"],ids["Pending"]],group)):
            raise RuntimeError("Already approved memberships were offered again")

        native, nentry=authenticate(base,"EmailDirectoryJunior")
        native_url=nentry + mode + "batch"
        if "Module not accessible" not in native.fetch(native_url,expected_status=503):
            raise RuntimeError("The original email-only actor reached the operation selector")
        stage("native")
        if values(native.fetch(native_url)) != {"activate","deactivate"}:
            raise RuntimeError("Native account administration implied ban or group powers")
        selected=review(native,native_url,"deactivate",[ids["Inactive"]])
        changed(apply(native,native_url,selected),1)
        if int(state()["users"][str(ids["Inactive"])]["user_type"]) != 1:
            raise RuntimeError("The separately authorised native account operation failed")
    finally:
        stage("clean")
