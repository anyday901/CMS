<?php

namespace App\Affiliates;

use App\Models\Activity;
use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Joining the program, referral links and who referred whom. A visit to
 * an affiliate's link leaves a cookie; a client who signs up at checkout
 * while it is there is recorded as their referral. Staff can set or
 * change a client's referrer by hand.
 */
class Referrals
{
    /** Holds the affiliate code from the last referral link followed. */
    public const COOKIE = 'affiliate';

    public function __construct(private AffiliateProgram $program) {}

    /** Makes a client an affiliate, from the portal or by staff. Returns the one they already have, if any. */
    public function join(Client $client): Affiliate
    {
        if ($client->affiliate) {
            return $client->affiliate;
        }

        $affiliate = new Affiliate;
        $affiliate->client_id = $client->id;
        $affiliate->code = Affiliate::newCode();
        $affiliate->save();
        $affiliate->setRelation('client', $client);
        $client->setRelation('affiliate', $affiliate);

        Activity::record('Joined the affiliate program', $client);
        AffiliateHooks::signedUp($affiliate);

        return $affiliate;
    }

    /** The active affiliate with this code, while the program is on. */
    public function find(?string $code): ?Affiliate
    {
        if (! $this->program->enabled() || blank($code)) {
            return null;
        }

        return Affiliate::where('code', strtolower(trim($code)))->where('status', Affiliate::ACTIVE)->first();
    }

    /**
     * Counts a visit to a referral link and remembers the code in a cookie.
     * A browser that already has this code isn't counted again.
     */
    public function visit(Request $request, string $code): void
    {
        $affiliate = $this->find($code);

        if ($affiliate === null) {
            return;
        }

        if ($request->cookie(self::COOKIE) !== $affiliate->code) {
            Affiliate::whereKey($affiliate->id)->increment('visits');
        }

        Cookie::queue(self::COOKIE, $affiliate->code, $this->program->cookieDays() * 24 * 60);
    }

    /** Records the referral of a client who just signed up at checkout, when a referral cookie came with them. */
    public function signedUp(Request $request, Client $client): ?AffiliateReferral
    {
        $affiliate = $this->find((string) $request->cookie(self::COOKIE));

        if ($affiliate === null || $affiliate->client_id === $client->id) {
            return null;
        }

        $referral = AffiliateReferral::create(['affiliate_id' => $affiliate->id, 'client_id' => $client->id]);
        Cookie::queue(Cookie::forget(self::COOKIE));
        Activity::record("Referred by affiliate {$affiliate->code} (client #{$affiliate->client_id})", $client);

        return $referral;
    }

    /**
     * Staff set, change or remove (null) who referred a client. Commissions
     * already earned stay with the affiliate who earned them.
     *
     * @throws InvalidArgumentException when the affiliate is the client themselves
     */
    public function setReferrer(Client $client, ?Affiliate $affiliate): void
    {
        if ($affiliate !== null && $affiliate->client_id === $client->id) {
            throw new InvalidArgumentException('A client can\'t be their own referrer.');
        }

        DB::transaction(function () use ($client, $affiliate) {
            $current = $client->referral()->first();

            if ($current?->affiliate_id === $affiliate?->id) {
                return;
            }

            if ($affiliate === null) {
                $current?->delete();
                Activity::record('Removed the client\'s referrer', $client, ['affiliate_id' => $current?->affiliate_id]);

                return;
            }

            // A fresh referral, so the new affiliate's first-invoice and month limits start now.
            $current?->delete();
            AffiliateReferral::create(['client_id' => $client->id, 'affiliate_id' => $affiliate->id]);
            Activity::record(($current ? 'Changed the client\'s referrer to' : 'Set the client\'s referrer to')." affiliate {$affiliate->code} (client #{$affiliate->client_id})", $client);
        });
    }
}
