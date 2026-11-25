"""Run the native scheduler and follow phpBB's ordinary forum-pruning ACP forms."""
import re
import time
from urllib.parse import urljoin

from http_board import FormFields, Page, admin_url


def browse(admin, verify):
    def submit(url, page, form_id, changes):
        action, _ = Page(page).forms[form_id]
        fields = FormFields(page, form_id).values
        fields.update(changes)
        if "creation_time" in fields:
            time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return admin.fetch(urljoin(url, action), fields)

    def manual(url, posted, viewed, valid=True):
        chooser = admin.fetch(url)
        for forum in (653, 654):
            if not re.search(fr'<option\b[^>]*value="{forum}"[^>]*>[^<]*Age fixture {forum}', chooser):
                raise RuntimeError(f"The native ACP selector omitted test forum {forum}")
        settings = submit(url, chooser, "acp_prune", {"f[]": [653, 654]})
        if 'id="prune_days"' not in settings or 'id="prune_vieweddays"' not in settings:
            raise RuntimeError("The native ACP did not open both age inputs for the selected forum")
        prompt = submit(url, settings, "acp_prune", {"prune_days": posted, "prune_vieweddays": viewed, "submit": "1"})
        if not valid:
            if "confirm" in Page(prompt).forms or not re.search(r"(von 1 bis 36500 Tagen|from 1 to 36500 days)", prompt):
                raise RuntimeError("An invalid age did not stop at the native ACP warning")
            return
        if "confirm" not in Page(prompt).forms:
            raise RuntimeError("The native ACP did not ask for confirmation")
        button = re.search(r'<input\b[^>]*name="confirm"[^>]*value="([^"]+)"', prompt)
        if not button:
            raise RuntimeError("The native ACP did not render its confirmation button")
        return submit(url, prompt, "confirm", {"confirm": button[1]})

    verify("seed")
    try:
        for phase in ("rules", "queue", "resume", "nested"):
            verify(phase)
        url = admin_url(admin) + "&i=acp_prune&mode=forums"
        manual(url, "", "", valid=False)
        verify("manual-before")
        for topics, posts in ((3, 4), (0, 0)):
            result = manual(url, "30", "20")
            rows = re.findall(r"<tr>\s*<td[^>]*>\s*Age fixture (\d+)\s*</td>\s*<td[^>]*>\s*(\d+)\s*</td>"
                              r"\s*<td[^>]*>\s*(\d+)\s*</td>\s*</tr>", result)
            counts = {int(f): (int(t), int(p)) for f, t, p in rows}
            expected = {653: (topics, posts), 654: (0, 0)}
            if counts != expected:
                raise RuntimeError(f"Native ACP reported {counts} pruned topics/posts, expected {expected}")
            verify("manual-after")
    finally:
        verify("cleanup")
