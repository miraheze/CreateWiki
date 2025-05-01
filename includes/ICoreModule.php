<?php

namespace Miraheze\CreateWiki;

// This will eventually be moved to ManageWiki and
// only loaded here when we are registering a provider
// for ManageWiki core when this becomes possible.
// TODO: add all the methods that should also be present
// for ManageWiki core.
interface ICoreModule extends IConfigModule {
}
