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
