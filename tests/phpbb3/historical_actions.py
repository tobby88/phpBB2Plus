"""Check archive entries through the actual phpBB ACP log viewer."""
from html import unescape
from http_board import admin_url


def browse(owner):
    root = admin_url(owner) + "&i=acp_logs&keywords=Archivname&mode="
    private = owner.fetch(root + "admin")
    if ("Thema #1 gesperrt" not in private or "Archivname 📗 & Co" not in unescape(private)
            or "Konto 1, Eintrag 901" not in private or "192.0.2.45" not in private):
        raise RuntimeError("The native ACP did not render the historic event and its recorded actor")
    if "Konto 1, Eintrag 901" in owner.fetch(root + "mod"):
        raise RuntimeError("The historical admin-only archive appeared in the moderator log")
    unknown = owner.fetch(admin_url(owner) + "&i=acp_logs&mode=admin&keywords=unrecorded")
    if "archive &lt;note&gt;" not in unknown or "Thema #990006" not in unknown or "„0“" not in unknown:
        raise RuntimeError("The ACP could not show a custom operation whose actor and topic are gone")
