from http_board import Browser


def browse(base, owner):
    guest = Browser(base)
    index = guest.fetch("index.php")
    if "Recovered public room" not in index or "Private child fixture" in index or "Private loop fixture" in index:
        raise RuntimeError("The recovered branches did not retain their original guest permissions")
    if "Recovered public room" not in guest.fetch("viewforum.php?f=51"):
        raise RuntimeError("The public forum lost its original native forum ID")
    if "Private child fixture" not in owner.fetch("viewforum.php?f=52") or "Private loop fixture" not in owner.fetch("viewforum.php?f=53"):
        raise RuntimeError("An administrator cannot read the private branches after recovery")
    if "Wiederfundprobe" not in owner.fetch("viewtopic.php?p=14720"):
        raise RuntimeError("The original post bookmark no longer opens the recovered content for an administrator")
    listing = guest.fetch("viewforum.php?f=14")
    if any(title in listing for title in ("Former global fixture", "Held shadow fixture", "Grüße im Archiv")):
        raise RuntimeError("A public forum listed content that lost its original forum")
    if "Wiederhergestellte Themen" in index:
        raise RuntimeError("A guest can see the recovery forum on the index")
    denied = guest.fetch("viewtopic.php?p=14720")
    if "Wiederfundprobe" in denied or "Grüße im Archiv" in denied:
        raise RuntimeError("The native post bookmark disclosed a recovered post to a guest")
