<?php

namespace Xoco70\LaravelTournaments\TreeGen;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Xoco70\LaravelTournaments\Contracts\TreeGenerable;
use Xoco70\LaravelTournaments\Exceptions\TreeGenerationException;
use Xoco70\LaravelTournaments\Models\Championship;
use Xoco70\LaravelTournaments\Models\ChampionshipSettings;
use Xoco70\LaravelTournaments\Models\Fight;
use Xoco70\LaravelTournaments\Models\FightersGroup;

abstract class TreeGen implements TreeGenerable
{
    protected $groupBy;
    protected $tree;
    public $championship;
    public $settings;
    protected $numFighters;

    abstract protected function pushEmptyGroupsToTree($numFighters);

    abstract protected function generateFights();

    abstract protected function createByeFighter();

    abstract protected function chunkAndShuffle(Collection $fightersByEntity);

    abstract protected function addFighterToGroup(FightersGroup $group, $fighter);

    abstract protected function syncGroup(FightersGroup $group, $fighters);

    abstract protected function getByeGroup($fighters);

    abstract protected function getFighter($fighterId);

    abstract protected function getFighters();

    abstract protected function getNumRounds($fightersCount);

    /**
     * @param Championship $championship
     * @param $groupBy
     */
    public function __construct(Championship $championship, $groupBy)
    {
        $this->championship = $championship;
        $this->groupBy = $groupBy;
        $this->settings = $championship->getSettings();
        $this->tree = new Collection();
    }


    /**
     * Generate tree groups for a championship.
     *
     * @throws TreeGenerationException
     */
    public function run()
    {
        $this->generateAllTrees();
        $this->generateAllFights();
    }

    /**
     * Get the biggest entity group
     * @param $userGroups
     *
     * @return int
     */
    private function getMaxFightersByEntity($userGroups): int
    {
        return $userGroups
            ->sortByDesc(function ($group) {
                return $group->count();
            })
            ->first()
            ->count();

    }

    /**
     * Get Competitor's list ordered by entities
     * Countries for Internation Tournament, State for a National Tournament, etc.
     *
     * @param $fighters
     * @return Collection
     */
    private function getFightersByEntity($fighters): Collection
    {
        // Right now, we are treating users and teams as equals.
        // It doesn't matter right now, because we only need name attribute which is common to both models

        // $this->groupBy contains federation_id, association_id, club_id, etc.
        if (($this->groupBy) != null) {
            $fighterGroups = $fighters->groupBy($this->groupBy); // Collection of Collection
        } else {
            $fighterGroups = $fighters->chunk(1); // Collection of Collection
        }
        return $fighterGroups;
    }

    /**
     * Get the size the first round will have
     * @param $fighterCount
     * @param $groupSize
     * @return int
     */
    protected function getTreeSize($fighterCount, $groupSize)
    {
        $squareMultiplied = collect([1, 2, 4, 8, 16, 32, 64])
            ->map(function ($item) use ($groupSize) {
                return $item * $groupSize;
            }); // [4, 8, 16, 32, 64,...]

        foreach ($squareMultiplied as $limit) {
            if ($fighterCount <= $limit) {
                $treeSize = $limit;
                $numAreas = $this->championship->getSettings()->fightingAreas;
                $fighterCountPerArea = $treeSize / $numAreas;
                if ($fighterCountPerArea < $groupSize) {
                    $treeSize = $treeSize * $numAreas;
                }
                return $treeSize;
            }

        }


        return 64 * $groupSize;

    }

    /**
     * Repart BYE in the tree,
     * @param $fighterGroups
     * @param int $max
     *
     * @return Collection
     */
    private function repart($fighterGroups, $max)
    {
        $fighters = new Collection();
        for ($i = 0; $i < $max; $i++) {
            foreach ($fighterGroups as $fighterGroup) {
                $fighter = $fighterGroup->values()->get($i);
                if ($fighter != null) {
                    $fighters->push($fighter);
                }
            }
        }

        return $fighters;
    }

    /**
     * Insert byes in an homogen way.
     *
     * @param Collection $fighters
     * @param Collection $byeGroup
     *
     * @return Collection
     */
    private function insertByes(Collection $fighters, Collection $byeGroup)
    {
        $bye = count($byeGroup) > 0 ? $byeGroup[0] : [];
        $sizeFighters = count($fighters);
        $sizeGroupBy = count($byeGroup);

        $frequency = $sizeGroupBy != 0
            ? (int)floor($sizeFighters / $sizeGroupBy)
            : -1;

        // Create Copy of $competitors
        return $this->getFullFighterList($fighters, $frequency, $sizeGroupBy, $bye);
    }

    /**
     * @param Collection $usersByArea
     */
    public function generateGroupsForRound(Collection $usersByArea, $round)
    {
        $order = 1;
        foreach ($usersByArea as $fightersByEntity) {
            // Chunking to make small round robin groups
            $chunkedFighters = $this->chunkAndShuffle($fightersByEntity);
            foreach ($chunkedFighters as $fighters) {
                $fighters = $fighters->pluck('id');
                if (!app()->runningUnitTests()) {
                    $fighters = $fighters->shuffle();
                }
                $group = $this->saveGroup($order, $round, null);
                $this->syncGroup($group, $fighters);
                $order++;
            }
        }
    }

    /**
     * @param $order
     * @param $round
     * @param $parent
     * @return FightersGroup
     */
    protected function saveGroup($order, $round, $parent): FightersGroup
    {
        $group = new FightersGroup();
        $group->area = $this->getNumArea($round, $order);

        $group->order = $order;
        $group->round = $round;
        $group->championship_id = $this->championship->id;
        if ($parent != null) {
            $group->parent_id = $parent->id;
        }
        $group->save();
        return $group;
    }


    /**
     * @param integer $groupSize
     * @return Collection
     */
    public function createByeGroup($groupSize): Collection
    {
        $byeFighter = $this->createByeFighter();
        $group = new Collection();
        for ($i = 0; $i < $groupSize; $i++) {
            $group->push($byeFighter);
        }
        return $group;
    }

    /**
     * @param $fighters
     * @param Collection $fighterGroups
     * @return Collection
     */
    public function adjustFightersGroupWithByes($fighters, $fighterGroups): Collection
    {
        $tmpFighterGroups = clone $fighterGroups;
        $byeGroup = $this->getByeGroup($fighters);


        // Get biggest competitor's group
        $max = $this->getMaxFightersByEntity($tmpFighterGroups);

        // We reacommodate them so that we can mix them up and they don't fight with another competitor of his entity.

        $fighters = $this->repart($fighterGroups, $max);
        $fighters = $this->insertByes($fighters, $byeGroup);
        return $fighters;
    }

    /**
     * Get All Groups on previous round
     * @param $currentRound
     * @return Collection
     */
    private function getPreviousRound($currentRound)
    {
        $previousRound = $this->championship->groupsByRound($currentRound + 1)->get();
        return $previousRound;
    }

    /**
     * Get the next group on the right ( parent ), final round being the ancestor
     * @param $matchNumber
     * @param Collection $previousRound
     * @return mixed
     */
    private function getParentGroup($matchNumber, $previousRound)
    {
        $parentIndex = intval(($matchNumber + 1) / 2);
        $parent = $previousRound->get($parentIndex - 1);
        return $parent;
    }


    /**
     * Group Fighters by area
     * @return Collection
     * @throws TreeGenerationException
     */
    private function getFightersByArea()
    {
        // If previous trees already exists, delete all
        $this->championship->fightersGroups()->delete();
        $areas = $this->settings->fightingAreas;
        $fighters = $this->getFighters();
        // If there is less than 2 competitors average by area
        if ($fighters->count() / $areas < ChampionshipSettings::MIN_COMPETITORS_BY_AREA) {
            throw new TreeGenerationException(trans('msg.min_competitor_required', ['number' => config('laravel-tournaments.MIN_COMPETITORS_X_AREA')]));
        }

        if ($this->settings->hasPreliminary && $fighters->count() / ($this->settings->preliminaryGroupSize * $areas) < 1 ) {
            throw new TreeGenerationException(trans('msg.min_competitor_required', ['number' => config('laravel-tournaments.MIN_COMPETITORS_X_AREA')]));
        }

        // Get Competitor's / Team list ordered by entities ( Federation, Assoc, Club, etc...)
        $fighterByEntity = $this->getFightersByEntity($fighters); // Chunk(1)
        $fightersWithBye = $this->adjustFightersGroupWithByes($fighters, $fighterByEntity);
        // Chunk user by areas
        return $fightersWithBye->chunk(count($fightersWithBye) / $areas);
    }

    /**
     * Attach a parent to every child for nestedSet Navigation
     * @param $numFightersElim
     */
    private function addParentToChildren($numFightersElim)
    {
        $numRounds = $this->getNumRounds($numFightersElim);
        $groupsDesc = $this->championship
            ->fightersGroups()
            ->where('round', '<', $numRounds)
            ->orderByDesc('id')->get();

        $groupsDescByRound = $groupsDesc->groupBy('round');

        foreach ($groupsDescByRound as $round => $groups) {
            $previousRound = $this->getPreviousRound($round);
            foreach ($groups->reverse()->values() as $matchNumber => $group) {
                $parent = $this->getParentGroup($matchNumber + 1, $previousRound);
                $group->parent_id = $parent->id;
                $group->save();
            }
        }
    }

    /**
     * @param Collection $fighters
     * @param $frequency
     * @param $sizeGroupBy
     * @param $bye
     * @return Collection
     */
    private function getFullFighterList(Collection $fighters, $frequency, $sizeGroupBy, $bye): Collection
    {
        $newFighters = new Collection();
        $count = 0;
        $byeCount = 0;
        foreach ($fighters as $fighter) {
            if ($this->shouldInsertBye($frequency, $sizeGroupBy, $count, $byeCount)) {
                $newFighters->push($bye);
                $byeCount++;
            }
            $newFighters->push($fighter);
            $count++;
        }
        return $newFighters;
    }

    /**
     * @param $frequency
     * @param $sizeGroupBy
     * @param $count
     * @param $byeCount
     * @return bool
     */
    private function shouldInsertBye($frequency, $sizeGroupBy, $count, $byeCount): bool
    {
        return $frequency != -1 && $count % $frequency == 0 && $byeCount < $sizeGroupBy;
    }


    /**
     * Destroy Previous Fights for demo
     */
    protected function destroyPreviousFights()
    {
        // Delete previous fight for this championship
        $arrGroupsId = $this->championship->fightersGroups()->get()->pluck('id');
        Fight::destroy($arrGroupsId);
    }


    /**
     * Generate Fights for next rounds
     */
    public function generateNextRoundsFights()
    {
        $fightersCount = $this->championship->competitors->count() + $this->championship->teams->count();
        $maxRounds = $this->getNumRounds($fightersCount);
        for ($numRound = 1; $numRound < $maxRounds; $numRound++) {
            $groupsByRound = $this->championship->fightersGroups()->where('round', $numRound)->with('parent', 'children')->get();
            $this->updateParentFight($groupsByRound); // should be groupsByRound
        }
    }

    /**
     * @param $groupsByRound
     */
    protected function updateParentFight($groupsByRound)
    {
        foreach ($groupsByRound as $keyGroup => $group) {
            $parentGroup = $group->parent;
            if ($parentGroup == null) break;
            $parentFight = $parentGroup->fights->get(0);

            // determine whether c1 or c2 must be updated
            $this->chooseAndUpdateParentFight($keyGroup, $group, $parentFight);
        }
    }

    /**
     * @param $group
     * @param $parentFight
     */
    protected function chooseAndUpdateParentFight($keyGroup, FightersGroup $group, Fight $parentFight)
    {
        // we need to know if the child has empty fighters, is this BYE or undetermined
        if ($group->hasDeterminedParent()) {
            $valueToUpdate = $group->getValueToUpdate(); // This should be OK
            if ($valueToUpdate != null) {
                $fighterToUpdate = $group->getParentFighterToUpdate($keyGroup);
                $parentFight->$fighterToUpdate = $valueToUpdate;
                $parentFight->save();
                // Add fighter to pivot table
                $parentGroup = $parentFight->group;

                $fighter = $this->getFighter($valueToUpdate);
                $this->addFighterToGroup($parentGroup, $fighter);
            }
        }
    }


    /**
     * Calculate the area of the group ( group is still not created )
     * @param $round
     * @param $order
     * @return int
     */
    protected function getNumArea($round, $order)
    {
        $totalAreas = $this->settings->fightingAreas;
        $numFighters = $this->championship->fighters->count(); // 4
        $numGroups = $this->getTreeSize($numFighters, $this->championship->getGroupSize()) / $this->championship->getGroupSize(); // 1 -> 1

        $areaSize = $numGroups / ($totalAreas * pow(2, $round - 1));

        $numArea = intval(ceil($order / $areaSize)); // if round == 4, and second match 2/2 = 1 BAD
//        dump($numArea);
        return $numArea;
    }

    /**
     *
     */
    protected function generateAllTrees()
    {
        $usersByArea = $this->getFightersByArea();
        $numFighters = sizeof($usersByArea->collapse());
        $this->generateGroupsForRound($usersByArea, 1);
        $this->pushEmptyGroupsToTree($numFighters); // Abstract
        $this->addParentToChildren($numFighters);
    }

    /**
     *
     */
    protected function generateAllFights()
    {
        $this->generateFights(); // Abstract
        //TODO In direct elimination without Prelim, short_id are not generating well
        $this->generateNextRoundsFights();
        Fight::generateFightsId($this->championship);
    }
}
